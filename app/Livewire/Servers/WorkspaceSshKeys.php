<?php

namespace App\Livewire\Servers;

use App\Jobs\PreviewDriftJob;
use App\Jobs\SyncAuthorizedKeysJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\EmitsPanelEvent;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\OrganizationSshKey;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\ServerSshKeyAuditEvent;
use App\Models\TeamSshKey;
use App\Models\UserSshKey;
use App\Services\Servers\OrganizationTeamSshKeyServerDeployer;
use App\Services\Servers\ServerAuthorizedKeysAuditLogger;
use App\Services\Servers\ServerAuthorizedKeysDiffPreview;
use App\Services\Servers\ServerAuthorizedKeysSynchronizer;
use App\Services\Servers\ServerPasswdUserLister;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Servers\SshKeyLabelTemplate;
use App\Services\Servers\SshPublicKeyFingerprint;
use App\Support\OpenSshEd25519KeyPairGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceSshKeys extends Component
{
    use ConfirmsActionWithModal;
    use EmitsPanelEvent;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    /** @var 'keys'|'preview'|'advanced'|'activity' */
    public string $ssh_workspace_tab = 'keys';

    public string $new_auth_name = '';

    public string $new_auth_key = '';

    public string $new_target_linux_user = '';

    public ?string $new_review_after = null;

    public ?string $profile_key_id = null;

    /** @var list<string> */
    public array $system_users = [];

    /** @var array<string, array{remote: list<string>, desired: list<string>, added: list<string>, removed: list<string>}>|null */
    public ?array $diff_result = null;

    /**
     * Console transcript captured while {@see self::previewDiff()} runs — surfaced as the
     * Drift tab's "View output" panel so the operator can see which targets were read and
     * whether anything errored without parsing the diff structure.
     *
     * @var list<string>
     */
    public array $diff_output = [];

    public bool $advanced_disable_sync = false;

    public bool $advanced_health_check = false;

    public string $advanced_label_template = '';

    public string $deploy_org_key_id = '';

    public string $deploy_team_key_id = '';

    public string $deploy_target_linux_user = '';

    /** @var array<string, string> */
    public array $reviewDates = [];

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->system_users = $this->baselineSystemUsers();
        $this->new_target_linux_user = (string) ($server->ssh_user ?: 'root');
        $this->deploy_target_linux_user = $this->new_target_linux_user;
        $this->hydrateAdvancedFromServer();
        $this->loadReviewDateInputs();
    }

    protected function loadReviewDateInputs(): void
    {
        $this->reviewDates = [];
        $this->server->loadMissing('authorizedKeys');
        foreach ($this->server->authorizedKeys as $ak) {
            $this->reviewDates[$ak->id] = $ak->review_after?->format('Y-m-d') ?? '';
        }
    }

    protected function hydrateAdvancedFromServer(): void
    {
        $m = $this->server->meta ?? [];
        $this->advanced_disable_sync = (bool) data_get($m, config('server_ssh_keys.meta_disable_sync_key'));
        $this->advanced_health_check = (bool) data_get($m, config('server_ssh_keys.meta_health_check_key'));
        $this->advanced_label_template = (string) data_get($m, config('server_ssh_keys.meta_label_template_key'), '');
    }

    /**
     * @return list<string>
     */
    protected function baselineSystemUsers(): array
    {
        $u = (string) $this->server->ssh_user;
        if ($u === '') {
            return [];
        }

        return [$u];
    }

    public function updatedProfileKeyId(?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $key = UserSshKey::query()
            ->where('user_id', Auth::id())
            ->whereKey($value)
            ->first();

        if ($key) {
            $this->applyLabelTemplate($key->name, (string) $this->new_target_linux_user);
            $this->new_auth_key = $key->public_key;
        }
    }

    protected function applyLabelTemplate(string $name, string $linuxUser): void
    {
        $tpl = SshKeyLabelTemplate::resolveTemplate($this->server);
        $this->new_auth_name = SshKeyLabelTemplate::apply($tpl, $name, $linuxUser, $this->server);
    }

    public function clearProfileSelection(): void
    {
        $this->profile_key_id = null;
    }

    public function generateNewAuthorizedKeyPair(): void
    {
        $this->authorize('update', $this->server);

        try {
            [$private, $public] = OpenSshEd25519KeyPairGenerator::generate();
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        if (! UserSshKey::publicKeyLooksValid($public)) {
            $this->toastError(__('Generated key was invalid. Try again or generate a key locally with ssh-keygen.'));

            return;
        }

        $this->profile_key_id = null;

        if (trim($this->new_auth_name) === '') {
            $this->new_auth_name = __('Generated key');
        }

        $this->new_auth_key = $public;

        $this->dispatch(
            'dply-ssh-keypair-generated',
            privateKey: $private,
            publicKey: $public,
        );

        $this->toastSuccess(__('A new key pair was generated. Copy your private key from the dialog, then use “Add SSH key” and “Sync authorized_keys”.'));
    }

    #[On('personal-ssh-key-created')]
    public function refreshProfileKeysAfterCreate(): void
    {
        $this->toastSuccess(__('SSH key saved. Select it below to attach it to this server, then sync authorized_keys.'));
    }

    public function loadSystemUsers(ServerPasswdUserLister $lister): void
    {
        $this->authorize('update', $this->server);

        try {
            $names = $lister->listUsernames($this->server->fresh());
            $merged = array_values(array_unique([...$this->baselineSystemUsers(), ...$names]));
            sort($merged);
            $this->system_users = $merged;
            $this->toastSuccess(__('Loaded system users from the server.'));
        } catch (\Throwable $e) {
            $this->toastError($this->friendlyWorkspaceError($e, __('Dply could not connect to the server to load system users.')));
        }
    }

    /**
     * Submit-time gate for {@see saveAdvancedSettings}. The "Disable authorized_keys sync"
     * toggle is the workspace's break-glass: flipping it on stops every future sync (manual
     * and automated) until turned back off. Worth a confirm beat. We only intercept on the
     * false→true transition; turning it off, or any save where the toggle isn't changing,
     * commits inline.
     */
    public function requestSaveAdvancedSettings(): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'advanced_label_template' => ['nullable', 'string', 'max:500'],
        ]);

        $persistedDisable = (bool) data_get($this->server->fresh()->meta ?? [], config('server_ssh_keys.meta_disable_sync_key'));
        $disablingNow = $this->advanced_disable_sync && ! $persistedDisable;

        if ($disablingNow) {
            $this->openConfirmActionModal(
                'saveAdvancedSettings',
                [],
                __('Disable authorized_keys sync?'),
                __('Dply will stop writing :file on this server until you turn this off again. Manual "Sync now" clicks and any automated reconciliation are blocked. Useful as a break-glass when something on the server is misbehaving — turn it back off as soon as you can.', ['file' => 'authorized_keys']),
                __('Disable sync'),
                true,
            );

            return;
        }

        $this->saveAdvancedSettings();
    }

    public function saveAdvancedSettings(): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'advanced_label_template' => ['nullable', 'string', 'max:500'],
        ]);

        $meta = $this->server->fresh()->meta ?? [];
        $meta[config('server_ssh_keys.meta_disable_sync_key')] = $this->advanced_disable_sync;
        $meta[config('server_ssh_keys.meta_health_check_key')] = $this->advanced_health_check;
        if (trim($this->advanced_label_template) === '') {
            unset($meta[config('server_ssh_keys.meta_label_template_key')]);
        } else {
            $meta[config('server_ssh_keys.meta_label_template_key')] = $this->advanced_label_template;
        }

        $this->server->fresh()->update(['meta' => $meta]);
        $this->server->refresh();
        $this->hydrateAdvancedFromServer();
        $this->toastSuccess(__('SSH key settings saved.'));
    }

    /**
     * Dispatch a queued drift preview. Mirrors {@see syncAuthorizedKeys()} — generates a fresh
     * run id, writes pending state to server.meta, and returns immediately so the workspace
     * banner can show the live "running" state while the queued job streams output back via
     * the application cache. The polling loop ({@see pollSyncStatus}) hydrates `$diff_result`
     * once the run completes.
     */
    public function previewDiff(): void
    {
        $this->authorize('view', $this->server);

        if ($this->rejectIfSyncBusy()) {
            return;
        }

        $statusKey = config('server_ssh_keys.meta_drift_status_key');
        $current = (string) data_get($this->server->fresh()->meta ?? [], $statusKey);
        if (in_array($current, ['queued', 'running'], true)) {
            $this->toastError(__('A drift preview is already in flight on this server. Wait for it to finish before starting another.'));

            return;
        }

        $runId = (string) \Illuminate\Support\Str::ulid();
        $meta = $this->server->fresh()->meta ?? [];
        $meta[config('server_ssh_keys.meta_drift_run_id_key')] = $runId;
        $meta[$statusKey] = 'queued';
        $meta[config('server_ssh_keys.meta_drift_started_at_key')] = now()->toIso8601String();
        $meta[config('server_ssh_keys.meta_drift_finished_at_key')] = null;
        $meta[config('server_ssh_keys.meta_drift_error_key')] = null;
        $this->server->fresh()->update(['meta' => $meta]);
        $this->server->refresh();

        // Clear any prior local state so the banner doesn't briefly render a stale completed
        // transcript before the queued state takes over.
        $this->diff_output = [];
        $this->diff_result = null;
        $this->ssh_workspace_tab = 'preview';

        PreviewDriftJob::dispatch($this->server->id, $runId);
    }

    public function addAuthorizedKey(ServerAuthorizedKeysAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'new_auth_name' => 'required|string|max:120',
            'new_auth_key' => 'required|string|max:8000',
            'new_target_linux_user' => ['required', 'string', 'max:64', Rule::in($this->system_users)],
            'new_review_after' => ['nullable', 'date'],
            'profile_key_id' => [
                'nullable',
                'string',
                Rule::exists('user_ssh_keys', 'id')->where('user_id', Auth::id()),
            ],
        ]);

        $selected = trim($this->new_target_linux_user);
        $storedTarget = $selected === (string) $this->server->ssh_user ? '' : $selected;

        $tpl = SshKeyLabelTemplate::resolveTemplate($this->server);

        if ($this->profile_key_id) {
            $userKey = UserSshKey::query()
                ->where('user_id', Auth::id())
                ->whereKey($this->profile_key_id)
                ->firstOrFail();

            $finalName = SshKeyLabelTemplate::apply($tpl, $userKey->name, $selected, $this->server);

            $row = ServerAuthorizedKey::query()->updateOrCreate(
                [
                    'server_id' => $this->server->id,
                    'managed_key_type' => UserSshKey::class,
                    'managed_key_id' => $userKey->id,
                    'target_linux_user' => $storedTarget,
                ],
                [
                    'name' => $finalName,
                    'public_key' => trim($userKey->public_key),
                    'review_after' => $this->new_review_after,
                ]
            );
        } else {
            if (! UserSshKey::publicKeyLooksValid($this->new_auth_key)) {
                $this->addError('new_auth_key', __('That does not look like a valid SSH public key.'));

                return;
            }

            $finalName = SshKeyLabelTemplate::apply($tpl, $this->new_auth_name, $selected, $this->server);

            $row = ServerAuthorizedKey::query()->create([
                'server_id' => $this->server->id,
                'managed_key_type' => null,
                'managed_key_id' => null,
                'target_linux_user' => $storedTarget,
                'name' => $finalName,
                'public_key' => trim($this->new_auth_key),
                'review_after' => $this->new_review_after,
            ]);
        }

        $fp = SshPublicKeyFingerprint::forLine((string) $row->public_key);
        $audit->record(
            $this->server->fresh(),
            ServerSshKeyAuditEvent::EVENT_KEY_CREATED,
            [
                'authorized_key_id' => $row->id,
                'name' => $row->name,
                'fingerprints' => $fp,
            ],
            Auth::user(),
            Request::ip()
        );

        $this->new_auth_name = '';
        $this->new_auth_key = '';
        $this->new_review_after = null;
        $this->new_target_linux_user = (string) ($this->server->ssh_user ?: 'root');
        $this->profile_key_id = null;
        $this->loadReviewDateInputs();
        $this->dispatch('close-modal', 'add-ssh-key-modal');

        $this->emitPanelEvent(
            __('Key added — sync to apply'),
            [
                sprintf('> Added "%s" to the panel for user %s.', $row->name, $selected !== '' ? $selected : (string) $this->server->ssh_user),
                sprintf('  fingerprint SHA256:%s', $fp['sha256'] ?? '—'),
                '> Click "Sync now" to write this to the server\'s authorized_keys.',
            ],
        );

        $this->toastSuccess(__('Key saved. Click “Sync authorized_keys” to apply on the server.'));
    }


    public function updateKeyReviewFromInput(string $id, ServerAuthorizedKeysAuditLogger $audit): void
    {
        $date = $this->reviewDates[$id] ?? '';
        $this->updateKeyReviewAfter($id, $date !== '' ? $date : null, $audit);
    }

    public function updateKeyReviewAfter(string $id, ?string $date, ServerAuthorizedKeysAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);
        $key = ServerAuthorizedKey::query()
            ->where('server_id', $this->server->id)
            ->whereKey($id)
            ->firstOrFail();

        $key->update([
            'review_after' => $date !== null && $date !== '' ? $date : null,
        ]);

        $audit->record(
            $this->server->fresh(),
            ServerSshKeyAuditEvent::EVENT_KEY_UPDATED,
            ['authorized_key_id' => $key->id, 'review_after' => $key->review_after?->toDateString()],
            Auth::user(),
            Request::ip()
        );

        $this->loadReviewDateInputs();
        $this->toastSuccess(__('Review date updated.'));
    }

    public function deleteAuthorizedKey(string $id, ServerAuthorizedKeysAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);
        $key = ServerAuthorizedKey::query()
            ->where('server_id', $this->server->id)
            ->whereKey($id)
            ->firstOrFail();

        // Refuse to delete the last key targeting the login user — that's the key Dply uses to
        // SSH into this server, and dropping it would lock Dply out on the next sync. Operators
        // who really want a no-key state can clear it via uninstall/server removal flows; this
        // workspace stays opinionated.
        if ($this->isLastLoginUserKey($key)) {
            $this->toastError(__('Cannot delete the last key for the login user :user — Dply uses it to reach this server. Add another :user key first, then remove this one.', [
                'user' => (string) ($this->server->ssh_user ?: 'login'),
            ]));

            return;
        }

        $fp = SshPublicKeyFingerprint::forLine((string) $key->public_key);
        $audit->record(
            $this->server->fresh(),
            ServerSshKeyAuditEvent::EVENT_KEY_DELETED,
            [
                'authorized_key_id' => $key->id,
                'name' => $key->name,
                'fingerprints' => $fp,
            ],
            Auth::user(),
            Request::ip()
        );

        $key->delete();
        $this->loadReviewDateInputs();
        $this->toastSuccess(__('Key removed. Sync again to update the server.'));
    }

    /**
     * True when removing the given key would leave the server's SSH login user with zero keys
     * targeting them — the lockout case Dply itself can never recover from automatically. A row
     * with empty `target_linux_user` is treated as login-user-targeted (that's the convention the
     * synchronizer uses when grouping rows for sync).
     */
    public function isLastLoginUserKey(ServerAuthorizedKey $key): bool
    {
        $loginUser = (string) $this->server->ssh_user;
        if ($loginUser === '') {
            return false;
        }

        $rowTarget = (string) ($key->target_linux_user ?? '');
        $targetsLogin = $rowTarget === '' || $rowTarget === $loginUser;
        if (! $targetsLogin) {
            return false;
        }

        $loginKeyCount = $this->server->fresh(['authorizedKeys'])->authorizedKeys
            ->filter(static function ($k) use ($loginUser): bool {
                $t = (string) ($k->target_linux_user ?? '');

                return $t === '' || $t === $loginUser;
            })
            ->count();

        return $loginKeyCount <= 1;
    }

    /**
     * Conditional gate for {@see syncAuthorizedKeys}. The explainer banner promises that the
     * workspace warns before a sync that would lock people out — this is where the warning
     * lives. Two trigger conditions:
     *
     *   1. The tracked set is empty. Syncing would write an empty authorized_keys; everyone
     *      using SSH against the box (including Dply) loses access until restored manually.
     *   2. The set has no key targeting Dply's login user. Other system users may still have
     *      keys, but Dply itself loses the ability to drive the server from this dashboard.
     *
     * Safe path runs sync inline. Risky path opens the existing confirm-action modal pre-bound
     * to call {@see syncAuthorizedKeys} on confirmation, so the operator gets a single explicit
     * "yes, lock me out" beat.
     */
    /**
     * Server-side guard mirrored on the Blade side. Returns true (and surfaces a toast) when a
     * sync run is queued or actively running on this server, so any caller that would conflict
     * with the in-flight job — Sync now, deploy-from-org/team, drift refresh — can short-circuit
     * before it touches state. Prevents the foot-gun where the operator's mid-sync click would
     * either silently no-op (deploys writing rows that the running sync won't include) or queue
     * a competing SSH op against the same authorized_keys file.
     */
    /** A sync that's been "queued"/"running" longer than this is treated as stuck and
     *  unblocks new dispatches — covers the cases where the queue worker isn't running,
     *  the job died mid-flight without writing meta, or a deploy interrupted the run. */
    public const SYNC_STALE_THRESHOLD_SECONDS = 300;

    protected function isSyncBusy(): bool
    {
        $meta = $this->server->fresh()->meta ?? [];
        $status = (string) data_get($meta, config('server_ssh_keys.meta_sync_status_key'));

        if (! in_array($status, ['queued', 'running'], true)) {
            return false;
        }

        // Treat a long-running queued/running entry as stale so the operator isn't permanently
        // locked out by a worker that quietly died. The next dispatch will overwrite the meta.
        $startedAt = (string) data_get($meta, config('server_ssh_keys.meta_sync_started_at_key'));
        if ($startedAt === '') {
            return true;
        }
        try {
            return ! \Illuminate\Support\Carbon::parse($startedAt)
                ->lt(now()->subSeconds(self::SYNC_STALE_THRESHOLD_SECONDS));
        } catch (\Throwable) {
            // Unparseable started_at → fail open and let the operator retry.
            return false;
        }
    }

    protected function rejectIfSyncBusy(): bool
    {
        if (! $this->isSyncBusy()) {
            return false;
        }

        $this->toastError(__('A sync is already in flight on this server. Wait for it to finish before starting another action.'));

        return true;
    }

    public function requestSyncAuthorizedKeys(): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfSyncBusy()) {
            return;
        }

        $keys = $this->server->fresh(['authorizedKeys'])->authorizedKeys;

        if ($keys->isEmpty()) {
            $this->openConfirmActionModal(
                'syncAuthorizedKeys',
                [],
                __('Sync with no keys?'),
                __('Dply will write an empty authorized_keys to the server. Anyone currently using SSH (including Dply itself) will be locked out until you add a key out-of-band. This cannot be undone from the workspace.'),
                __('Sync anyway'),
                true,
            );

            return;
        }

        $loginUser = (string) $this->server->ssh_user;
        // An empty `target_linux_user` on a row means "default to the server's login user", so
        // those rows count toward the login user's keys when checking lockout risk.
        $hasKeyForLoginUser = $keys->contains(static function ($k) use ($loginUser): bool {
            $target = (string) ($k->target_linux_user ?? '');

            return $target === '' || $target === $loginUser;
        });

        if (! $hasKeyForLoginUser && $loginUser !== '') {
            $this->openConfirmActionModal(
                'syncAuthorizedKeys',
                [],
                __('Sync without a key for the login user?'),
                __('No key in this set targets :user — the user Dply uses to SSH into this server. Syncing now will lock Dply out until you restore the key out-of-band. Other system users may still keep their keys.', ['user' => $loginUser]),
                __('Sync anyway'),
                true,
            );

            return;
        }

        $this->syncAuthorizedKeys();
    }

    /**
     * Dispatch a queued sync. The actual SSH work happens inside {@see SyncAuthorizedKeysJob};
     * the workspace banner polls server.meta for status and reads the streaming output buffer
     * out of the application cache. Idempotent — if a sync is already running on this server,
     * we no-op and let the banner keep tracking the in-flight run.
     */
    public function syncAuthorizedKeys(): void
    {
        $this->authorize('update', $this->server);

        // Defense-in-depth — `requestSyncAuthorizedKeys` already gates on isSyncBusy(); this
        // catches direct invocations (e.g. the confirm-modal callback) and uses the same
        // staleness threshold so a stuck queued/running entry never permanently locks dispatch.
        if ($this->isSyncBusy()) {
            $this->toastError(__('A sync is already in flight on this server. Wait for it to finish before starting another.'));

            return;
        }

        $runId = (string) Str::ulid();
        $meta = $this->server->fresh()->meta ?? [];
        $meta[config('server_ssh_keys.meta_sync_run_id_key')] = $runId;
        $meta[config('server_ssh_keys.meta_sync_status_key')] = 'queued';
        $meta[config('server_ssh_keys.meta_sync_started_at_key')] = now()->toIso8601String();
        $meta[config('server_ssh_keys.meta_sync_finished_at_key')] = null;
        $meta[config('server_ssh_keys.meta_sync_error_key')] = null;
        $this->server->fresh()->update(['meta' => $meta]);
        $this->server->refresh();

        SyncAuthorizedKeysJob::dispatch(
            $this->server->id,
            $runId,
            Auth::id(),
            Request::ip(),
        );

        $this->toastSuccess(__('Sync queued — watch the banner for live output. You can leave this page; the job runs on the queue.'));
    }

    /**
     * Banner re-render tick — handles both sync and drift runs. Refreshes the server row so the
     * latest meta + queue-driven status lands on the page. On sync completion, reloads the
     * per-key review-date inputs (synced_at may have changed). On drift completion, hydrates
     * `$diff_output` and `$diff_result` from the cache payload the job wrote. Called via
     * wire:poll while either run is active.
     */
    public function pollSyncStatus(): void
    {
        $this->server->refresh();

        $syncStatus = (string) data_get($this->server->meta ?? [], config('server_ssh_keys.meta_sync_status_key'));
        if ($syncStatus === 'completed' || $syncStatus === 'failed') {
            $this->loadReviewDateInputs();
        }

        // A successful sync rewrote authorized_keys to match the desired set. Anything the user
        // was looking at in the Drift tab is now stale and would mislead ("but I just synced — why
        // is there still drift?"). Drop the structured diff and the transcript on transition to
        // completed; user can click Refresh preview to confirm the post-sync state.
        if ($syncStatus === 'completed' && $this->diff_result !== null) {
            $this->diff_result = null;
            $this->diff_output = [];

            $meta = $this->server->meta ?? [];
            unset(
                $meta[config('server_ssh_keys.meta_drift_run_id_key')],
                $meta[config('server_ssh_keys.meta_drift_status_key')],
                $meta[config('server_ssh_keys.meta_drift_started_at_key')],
                $meta[config('server_ssh_keys.meta_drift_finished_at_key')],
                $meta[config('server_ssh_keys.meta_drift_error_key')],
            );
            $this->server->fresh()->update(['meta' => $meta]);
            $this->server->refresh();
        }

        $driftStatus = (string) data_get($this->server->meta ?? [], config('server_ssh_keys.meta_drift_status_key'));
        $driftRunId = (string) data_get($this->server->meta ?? [], config('server_ssh_keys.meta_drift_run_id_key'));
        if ($driftRunId !== '') {
            $cacheKey = (string) config('server_ssh_keys.drift_output_cache_key_prefix', 'ssh_key_drift_output:').$driftRunId;
            $payload = Cache::get($cacheKey);
            if (is_array($payload)) {
                $lines = $payload['lines'] ?? [];
                $this->diff_output = is_array($lines) ? array_values(array_filter($lines, 'is_string')) : [];
                if ($driftStatus === 'completed' && isset($payload['diff_result']) && is_array($payload['diff_result'])) {
                    $this->diff_result = $payload['diff_result'];
                }
            }
        }
    }

    /**
     * Operator-initiated dismissal of a finished run banner. Clears the meta keys so the
     * banner stops rendering on subsequent polls. No-op while a run is still in flight —
     * dismissing mid-sync would just hide a banner the operator probably wants to see.
     */
    /**
     * Clear the drift-preview banner. Resets the run meta keys (so subsequent renders skip
     * the banner) and the local transcript. Preserves `$diff_result` because operators usually
     * want the structured diff to stay visible on the Drift tab even after dismissing the
     * banner. No-op while a run is still in flight — same rule as dismissSyncBanner.
     */
    public function dismissDriftBanner(): void
    {
        $this->authorize('view', $this->server);

        $status = (string) data_get($this->server->fresh()->meta ?? [], config('server_ssh_keys.meta_drift_status_key'));
        if (in_array($status, ['queued', 'running'], true)) {
            return;
        }

        $meta = $this->server->fresh()->meta ?? [];
        unset(
            $meta[config('server_ssh_keys.meta_drift_run_id_key')],
            $meta[config('server_ssh_keys.meta_drift_status_key')],
            $meta[config('server_ssh_keys.meta_drift_started_at_key')],
            $meta[config('server_ssh_keys.meta_drift_finished_at_key')],
            $meta[config('server_ssh_keys.meta_drift_error_key')],
        );
        $this->server->fresh()->update(['meta' => $meta]);
        $this->server->refresh();

        $this->diff_output = [];
    }

    public function dismissSyncBanner(): void
    {
        $this->authorize('update', $this->server);

        $status = (string) data_get($this->server->fresh()->meta ?? [], config('server_ssh_keys.meta_sync_status_key'));
        if (in_array($status, ['queued', 'running'], true)) {
            return;
        }

        $meta = $this->server->fresh()->meta ?? [];
        unset(
            $meta[config('server_ssh_keys.meta_sync_run_id_key')],
            $meta[config('server_ssh_keys.meta_sync_status_key')],
            $meta[config('server_ssh_keys.meta_sync_started_at_key')],
            $meta[config('server_ssh_keys.meta_sync_finished_at_key')],
            $meta[config('server_ssh_keys.meta_sync_error_key')],
        );
        $this->server->fresh()->update(['meta' => $meta]);
        $this->server->refresh();
    }

    /**
     * Streaming output buffer for the active (or most recent) sync run. Reads the cache
     * payload written by {@see SyncAuthorizedKeysJob} — empty list when no run has happened
     * recently, when the cache TTL has lapsed, or when the run hasn't emitted anything yet.
     *
     * @return list<string>
     */
    public function getSyncOutputLinesProperty(): array
    {
        $runId = (string) data_get($this->server->meta ?? [], config('server_ssh_keys.meta_sync_run_id_key'));
        if ($runId === '') {
            return [];
        }
        $payload = Cache::get((string) config('server_ssh_keys.sync_output_cache_key_prefix', 'ssh_key_sync_output:').$runId);
        if (! is_array($payload)) {
            return [];
        }
        $lines = $payload['lines'] ?? [];

        return is_array($lines) ? array_values(array_filter($lines, 'is_string')) : [];
    }

    protected function friendlyWorkspaceError(\Throwable $e, string $defaultMessage): string
    {
        $message = trim($e->getMessage());

        if ($message === '') {
            return $defaultMessage;
        }

        if (str_contains($message, 'Permission denied (publickey)')) {
            return $defaultMessage.' '.__('The server rejected the SSH key for :connection.', [
                'connection' => $this->server->getSshConnectionString(),
            ]);
        }

        if (str_contains($message, 'Could not create script directory')) {
            return $defaultMessage.' '.__('The server did not allow Dply to start a remote SSH task for :connection.', [
                'connection' => $this->server->getSshConnectionString(),
            ]);
        }

        if (str_contains($message, 'Failed to execute task:')) {
            return $defaultMessage;
        }

        return $message;
    }

    public function deployOrganizationKey(OrganizationTeamSshKeyServerDeployer $deployer): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfSyncBusy()) {
            return;
        }

        $this->validate([
            'deploy_org_key_id' => ['required', 'string', 'exists:organization_ssh_keys,id'],
            'deploy_target_linux_user' => ['required', 'string', 'max:64', Rule::in($this->system_users)],
        ]);

        $key = OrganizationSshKey::query()->whereKey($this->deploy_org_key_id)->firstOrFail();
        $selected = trim($this->deploy_target_linux_user);
        $stored = $selected === (string) $this->server->ssh_user ? '' : $selected;

        $result = $deployer->deployOrganizationKey(Auth::user(), $key, $this->server->fresh(), $stored);
        if ($result['ok']) {
            $this->toastSuccess($result['message']);
        } else {
            $this->toastError($result['message']);
        }
    }

    public function deployTeamKey(OrganizationTeamSshKeyServerDeployer $deployer): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfSyncBusy()) {
            return;
        }

        $this->validate([
            'deploy_team_key_id' => ['required', 'string', 'exists:team_ssh_keys,id'],
            'deploy_target_linux_user' => ['required', 'string', 'max:64', Rule::in($this->system_users)],
        ]);

        $key = TeamSshKey::query()->whereKey($this->deploy_team_key_id)->firstOrFail();
        $selected = trim($this->deploy_target_linux_user);
        $stored = $selected === (string) $this->server->ssh_user ? '' : $selected;

        $result = $deployer->deployTeamKey(Auth::user(), $key, $this->server->fresh(), $stored);
        if ($result['ok']) {
            $this->toastSuccess($result['message']);
        } else {
            $this->toastError($result['message']);
        }
    }

    public function render(): View
    {
        $this->server->refresh();
        $this->server->load(['authorizedKeys']);
        $user = Auth::user();

        $profileKeys = UserSshKey::query()
            ->where('user_id', $user?->id)
            ->orderBy('name')
            ->get();

        $orgKeys = $this->server->organization_id
            ? OrganizationSshKey::query()
                ->where('organization_id', $this->server->organization_id)
                ->orderBy('name')
                ->get()
            : collect();

        $teamKeys = $this->server->team_id
            ? TeamSshKey::query()
                ->where('team_id', $this->server->team_id)
                ->orderBy('name')
                ->get()
            : collect();

        $auditEvents = $this->server->sshKeyAuditEvents()->with('user')->limit(100)->get();

        $fingerprints = [];
        foreach ($this->server->authorizedKeys as $ak) {
            $fingerprints[$ak->id] = SshPublicKeyFingerprint::forLine((string) $ak->public_key);
        }

        return view('livewire.servers.workspace-ssh-keys', [
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
            'profileKeys' => $profileKeys,
            'serverHasPersonalProfileKey' => $this->server->hasPersonalUserSshKey($user),
            'orgKeys' => $orgKeys,
            'teamKeys' => $teamKeys,
            'auditEvents' => $auditEvents,
            'fingerprints' => $fingerprints,
        ]);
    }
}
