<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\CreateSftpAccountJob;
use App\Jobs\DeleteSftpAccountJob;
use App\Jobs\ResetSftpAccountPasswordJob;
use App\Jobs\SyncAuthorizedKeysJob;
use App\Models\ConsoleAction;
use App\Models\ServerAuthorizedKey;
use App\Models\SftpAccount;
use App\Models\UserSshKey;
use App\Services\Servers\ServerPasswdUserLister;
use App\Services\Servers\ServerSystemUserService;
use App\Services\Servers\SftpAccountProvisioner;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * FTP (SFTP) accounts for one site, hosted on the site's Files tab — the
 * surface an operator is already on when they want to hand someone file access.
 *
 * All SSH work is queued; nothing here touches a server on the render path.
 */
trait ManagesSiteFtpAccounts
{
    public bool $showFtpCreateModal = false;

    public string $ftp_username = '';

    public bool $showFtpAdoptModal = false;

    public string $ftp_adopt_username = '';

    public ?string $ftp_key_account_id = null;

    public string $ftp_key_name = '';

    public string $ftp_key_public = '';

    public string $ftp_key_profile_id = '';

    public ?string $ftp_error = null;

    public ?string $ftp_pending_delete_id = null;

    /**
     * The one time the generated password is visible.
     *
     * Deliberately NOT a public Livewire property: public properties are
     * serialized into the DOM snapshot and round-trip on every subsequent
     * request. As a protected property it lives for exactly the response that
     * created it, which is the reveal-once contract — dply never stores it, so
     * a lost password is resolved by resetting it, not by looking it up.
     */
    protected ?string $ftp_revealed_password = null;

    protected ?string $ftp_revealed_username = null;

    /** @return Collection<int, SftpAccount> */
    public function ftpAccounts()
    {
        return SftpAccount::query()
            ->where('site_id', $this->site->id)
            ->orderBy('username')
            ->get();
    }

    public function revealedFtpPassword(): ?string
    {
        return $this->ftp_revealed_password;
    }

    public function revealedFtpUsername(): ?string
    {
        return $this->ftp_revealed_username;
    }

    /**
     * True when uploads here will not reach the site's other servers. Sites in a
     * deploy sync group are N Site rows on N boxes; SFTP only ever writes to one.
     */
    public function ftpSyncGroupWarning(): bool
    {
        return $this->site->deploySyncGroups()->exists();
    }

    /**
     * The in-flight (or most recent) FTP run for this site, rendered by the
     * shared console-action banner — which also owns the wire:poll that keeps
     * this component re-rendering until the run goes terminal. That poll is
     * what flips an account row from "Provisioning…" to "Active" without a
     * manual refresh, so the banner is load-bearing, not decoration.
     */
    public function ftpConsoleRun(): ?ConsoleAction
    {
        return ConsoleAction::query()
            ->where('subject_type', $this->site->getMorphClass())
            ->where('subject_id', $this->site->id)
            ->where('kind', 'sftp_account')
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Persist a queued run BEFORE dispatch so the banner is on screen the
     * instant the operator clicks, rather than appearing on the first poll
     * after a worker picks the job up.
     *
     * Supersedes earlier finished runs for this site so the panel shows one
     * thing — the run just started — instead of stacking stale banners.
     */
    protected function seedFtpConsoleAction(string $label): ConsoleAction
    {
        ConsoleAction::query()
            ->where('subject_type', $this->site->getMorphClass())
            ->where('subject_id', $this->site->id)
            ->where('kind', 'sftp_account')
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED])
            ->update(['dismissed_at' => now()]);

        return ConsoleAction::query()->create([
            'subject_type' => $this->site->getMorphClass(),
            'subject_id' => $this->site->id,
            'kind' => 'sftp_account',
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => $label,
            'user_id' => Auth::id(),
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);
    }

    /**
     * Existing Linux accounts that could be given FTP access.
     *
     * Excludes anything already granted, and anything dply logs in as — the
     * Match block would force every dply SSH command into an SFTP session.
     *
     * @return list<string>
     */
    public function ftpAdoptableUsernames(): array
    {
        $taken = SftpAccount::query()
            ->where('server_id', $this->server->id)
            ->pluck('username')
            ->map(static fn ($u): string => strtolower((string) $u))
            ->all();

        $provisioner = app(SftpAccountProvisioner::class);

        return collect(app(ServerSystemUserService::class)->storedSystemUsersWithMetadata($this->server))
            ->pluck('username')
            ->filter(function ($username) use ($taken, $provisioner): bool {
                if (in_array(strtolower((string) $username), $taken, true)) {
                    return false;
                }

                try {
                    $provisioner->assertGrantableUsername($this->server, (string) $username);
                } catch (\Throwable) {
                    return false;
                }

                return true;
            })
            ->values()
            ->all();
    }

    /**
     * The deploy user can never join dply-sftp, but it already speaks SFTP over
     * its existing SSH key — so the panel offers connection details instead of
     * a button that would have to be refused.
     */
    public function ftpDeployUsername(): string
    {
        return trim((string) $this->server->ssh_user) ?: 'dply';
    }

    public function openFtpAdoptModal(): void
    {
        $this->authorize('update', $this->site);
        $this->ftp_error = null;
        $this->ftp_adopt_username = '';
        $this->showFtpAdoptModal = true;

        // The candidate list reads the server_system_users snapshot, which is
        // only written when someone visits the server's System users page and
        // loads it. Most operators reaching this modal never have — so without
        // this the list is empty on a server full of accounts. Probe on open
        // when the snapshot is cold: an explicit user action, never the render
        // path, and skipped once a snapshot exists.
        if ($this->ftpAdoptableUsernames() === []) {
            $this->loadFtpAdoptableAccounts();
        }
    }

    /**
     * SSH-probes /etc/passwd and persists the snapshot the candidate list reads.
     * Safe to call repeatedly. Failures surface inline rather than throwing —
     * an unreachable server should explain itself, not blank the modal.
     */
    public function loadFtpAdoptableAccounts(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->ftp_error = __('The server must be ready with SSH before loading accounts.');

            return;
        }

        try {
            app(ServerSystemUserService::class)->listPasswdUsersWithSiteCounts(
                $this->server->fresh(),
                app(ServerPasswdUserLister::class),
            );
        } catch (\Throwable $e) {
            $this->ftp_error = $e->getMessage();
        }
    }

    public function closeFtpAdoptModal(): void
    {
        $this->showFtpAdoptModal = false;
        $this->ftp_error = null;
    }

    /** Grants FTP access to an account that already exists on the server. */
    public function adoptFtpAccount(ServerSystemUserService $users): void
    {
        $this->authorize('update', $this->site);
        $this->ftp_error = null;

        try {
            $username = $users->validatePasswdStyleUsername($this->ftp_adopt_username);

            if (! in_array($username, $this->ftpAdoptableUsernames(), true)) {
                throw new \RuntimeException(__('That account is not available for FTP access.'));
            }

            $password = SftpAccount::generatePassword();

            $account = SftpAccount::create([
                'server_id' => $this->server->id,
                'site_id' => $this->site->id,
                'username' => $username,
                'source' => SftpAccount::SOURCE_ADOPTED,
                'home_path' => '/home/'.$username,
                'status' => SftpAccount::STATUS_PENDING,
                'created_by_user_id' => Auth::id(),
            ]);

            $run = $this->seedFtpConsoleAction(__('Granting FTP access to :user …', ['user' => $username]));
            CreateSftpAccountJob::dispatch((string) $account->id, $password, (string) Auth::id(), (string) $run->id);

            $this->ftp_revealed_password = $password;
            $this->ftp_revealed_username = $username;

            $this->showFtpAdoptModal = false;
            $this->toastSuccess(__('FTP access queued. The password is shown once — copy it now.'));
        } catch (\Throwable $e) {
            $this->ftp_error = $e->getMessage();
        }
    }

    /**
     * SSH keys already on file for these accounts, keyed by username.
     *
     * Reuses {@see ServerAuthorizedKey}, which is already per-Linux-user
     * (`target_linux_user`) — so FTP accounts need no key storage of their own,
     * and inherit the synchronizer's fingerprint RECONCILE. That matters most
     * for adopted accounts: the sync only ever removes keys dply itself wrote,
     * so an operator's pre-existing keys on that account survive untouched.
     *
     * @return array<string, \Illuminate\Support\Collection<int, ServerAuthorizedKey>>
     */
    public function ftpAccountKeys(): array
    {
        $usernames = SftpAccount::query()
            ->where('site_id', $this->site->id)
            ->pluck('username')
            ->all();

        if ($usernames === []) {
            return [];
        }

        return ServerAuthorizedKey::query()
            ->where('server_id', $this->server->id)
            ->whereIn('target_linux_user', $usernames)
            ->orderBy('name')
            ->get()
            ->groupBy('target_linux_user')
            ->all();
    }

    /**
     * The operator's own saved keys, so the common case is a pick rather than a
     * copy-paste round trip through their terminal.
     *
     * @return \Illuminate\Support\Collection<int, UserSshKey>
     */
    public function ftpProfileKeys()
    {
        return UserSshKey::query()
            ->where('user_id', Auth::id())
            ->orderBy('name')
            ->get(['id', 'name', 'public_key']);
    }

    public function openFtpKeyModal(string $accountId): void
    {
        $this->authorize('update', $this->site);
        $this->ftp_error = null;
        $this->ftp_key_name = '';
        $this->ftp_key_public = '';
        $this->ftp_key_profile_id = '';
        $this->ftp_key_account_id = $accountId;
    }

    public function closeFtpKeyModal(): void
    {
        $this->ftp_key_account_id = null;
        $this->ftp_error = null;
    }

    /**
     * Key auth alongside the password. The Match block leaves
     * PasswordAuthentication on for the group either way, so adding a key is
     * additive — an account can be used with either, and a customer who only
     * ever uses a key simply never types the generated one.
     */
    public function addFtpKey(): void
    {
        $this->authorize('update', $this->site);
        $this->ftp_error = null;

        try {
            $account = SftpAccount::query()
                ->where('site_id', $this->site->id)
                ->findOrFail($this->ftp_key_account_id);

            if ($this->ftp_key_profile_id !== '') {
                // One of the operator's own saved keys. Recorded as managed by
                // that UserSshKey (the same shape the server-level key screen
                // uses) so the row is traceable back to its owner, and
                // updateOrCreate keeps re-picking the same key idempotent.
                $profileKey = UserSshKey::query()
                    ->where('user_id', Auth::id())
                    ->findOrFail($this->ftp_key_profile_id);

                ServerAuthorizedKey::query()->updateOrCreate(
                    [
                        'server_id' => $this->server->id,
                        'managed_key_type' => UserSshKey::class,
                        'managed_key_id' => $profileKey->id,
                        'target_linux_user' => $account->username,
                    ],
                    [
                        'name' => trim($this->ftp_key_name) !== '' ? trim($this->ftp_key_name) : $profileKey->name,
                        'public_key' => trim((string) $profileKey->public_key),
                    ],
                );
            } else {
                if (! UserSshKey::publicKeyLooksValid($this->ftp_key_public)) {
                    throw new \RuntimeException(__('That does not look like a valid SSH public key.'));
                }

                $name = trim($this->ftp_key_name) !== ''
                    ? trim($this->ftp_key_name)
                    : __('FTP key for :user', ['user' => $account->username]);

                ServerAuthorizedKey::query()->create([
                    'server_id' => $this->server->id,
                    'target_linux_user' => $account->username,
                    'name' => $name,
                    'public_key' => trim($this->ftp_key_public),
                ]);
            }

            // The key reaches the box on the queue — an SSH round trip in the
            // HTTP request would hang the modal until max_execution_time.
            SyncAuthorizedKeysJob::dispatch(
                (string) $this->server->id,
                (string) Str::ulid(),
                (string) Auth::id(),
                request()->ip(),
            );

            $this->ftp_key_account_id = null;
            $this->toastSuccess(__('SSH key queued for :user.', ['user' => $account->username]));
        } catch (\Throwable $e) {
            $this->ftp_error = $e->getMessage();
        }
    }

    public function removeFtpKey(string $keyId): void
    {
        $this->authorize('update', $this->site);

        $usernames = SftpAccount::query()
            ->where('site_id', $this->site->id)
            ->pluck('username')
            ->all();

        // Scoped to this site's FTP accounts so the site surface can never
        // delete a key belonging to a shell user or to the deploy account.
        $key = ServerAuthorizedKey::query()
            ->where('server_id', $this->server->id)
            ->whereIn('target_linux_user', $usernames)
            ->find($keyId);

        if (! $key) {
            return;
        }

        $key->delete();

        SyncAuthorizedKeysJob::dispatch(
            (string) $this->server->id,
            (string) Str::ulid(),
            (string) Auth::id(),
            request()->ip(),
        );

        $this->toastSuccess(__('SSH key removal queued.'));
    }

    public function openFtpCreateModal(): void
    {
        $this->authorize('update', $this->site);
        $this->ftp_error = null;
        $this->ftp_username = '';
        $this->showFtpCreateModal = true;
    }

    public function closeFtpCreateModal(): void
    {
        $this->showFtpCreateModal = false;
        $this->ftp_error = null;
    }

    public function createFtpAccount(ServerSystemUserService $users, ServerPasswdUserLister $lister): void
    {
        $this->authorize('update', $this->site);
        $this->ftp_error = null;

        try {
            // Linux rules first (length, charset, reserved deploy user).
            $username = $users->validateNewUsername($this->ftp_username);

            // root and the deploy user. createUser() checks this again on the
            // worker; doing it here is what keeps a reserved name from becoming
            // a database row that fails out of band.
            $users->assertAcceptableCreateUsername($this->server, $username);
            app(SftpAccountProvisioner::class)->assertGrantableUsername($this->server, $username);

            // /etc/passwd is the real namespace — a name free in our table can
            // still be taken on the box by a shell user or a leftover account.
            if (SftpAccount::query()->where('server_id', $this->server->id)->where('username', $username)->exists()) {
                throw new \RuntimeException(__('An FTP account with that username already exists on this server.'));
            }

            foreach ($users->storedSystemUsersWithMetadata($this->server) as $row) {
                if (strtolower((string) $row['username']) === strtolower($username)) {
                    throw new \RuntimeException(__('That username is already a Linux account on this server. Pick another.'));
                }
            }

            $password = SftpAccount::generatePassword();

            $account = SftpAccount::create([
                'server_id' => $this->server->id,
                'site_id' => $this->site->id,
                'username' => $username,
                'home_path' => '/home/'.$username,
                'status' => SftpAccount::STATUS_PENDING,
                'created_by_user_id' => Auth::id(),
            ]);

            $run = $this->seedFtpConsoleAction(__('Creating FTP account :user …', ['user' => $username]));
            CreateSftpAccountJob::dispatch((string) $account->id, $password, (string) Auth::id(), (string) $run->id);

            // Shown in this response only. The job applies it on the box.
            $this->ftp_revealed_password = $password;
            $this->ftp_revealed_username = $username;

            $this->showFtpCreateModal = false;
            $this->toastSuccess(__('FTP account queued. The password is shown once — copy it now.'));
        } catch (\Throwable $e) {
            $this->ftp_error = $e->getMessage();
        }
    }

    public function resetFtpPassword(string $accountId): void
    {
        $this->authorize('update', $this->site);

        $account = SftpAccount::query()
            ->where('site_id', $this->site->id)
            ->findOrFail($accountId);

        $password = SftpAccount::generatePassword();
        $run = $this->seedFtpConsoleAction(__('Resetting password for :user …', ['user' => $account->username]));
        ResetSftpAccountPasswordJob::dispatch((string) $account->id, $password, (string) Auth::id(), (string) $run->id);

        $this->ftp_revealed_password = $password;
        $this->ftp_revealed_username = $account->username;
        $this->toastSuccess(__('Password reset queued. The new password is shown once — copy it now.'));
    }

    public function confirmDeleteFtpAccount(string $accountId): void
    {
        $this->authorize('update', $this->site);
        $this->ftp_pending_delete_id = $accountId;
    }

    public function cancelDeleteFtpAccount(): void
    {
        $this->ftp_pending_delete_id = null;
    }

    public function deleteFtpAccount(): void
    {
        $this->authorize('update', $this->site);

        $account = SftpAccount::query()
            ->where('site_id', $this->site->id)
            ->find($this->ftp_pending_delete_id);

        $this->ftp_pending_delete_id = null;

        if (! $account) {
            return;
        }

        $run = $this->seedFtpConsoleAction(__('Removing FTP account :user …', ['user' => $account->username]));
        DeleteSftpAccountJob::dispatch((string) $account->id, (string) Auth::id(), (string) $run->id);
        $this->toastSuccess(__('FTP account removal queued.'));
    }
}
