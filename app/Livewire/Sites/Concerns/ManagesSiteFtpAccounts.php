<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\CreateSftpAccountJob;
use App\Jobs\DeleteSftpAccountJob;
use App\Jobs\ResetSftpAccountPasswordJob;
use App\Models\ConsoleAction;
use App\Models\SftpAccount;
use App\Services\Servers\ServerPasswdUserLister;
use App\Services\Servers\ServerSystemUserService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

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
