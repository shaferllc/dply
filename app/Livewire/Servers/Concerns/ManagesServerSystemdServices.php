<?php

namespace App\Livewire\Servers\Concerns;

use App\Jobs\ServerManageRemoteSshJob;
use App\Jobs\SyncServerSystemdServicesJob;
use App\Models\NotificationSubscription;
use App\Models\Server;
use App\Models\ServerSystemdServiceAuditEvent;
use App\Models\ServerSystemdServiceState;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Notifications\AssignableNotificationChannels;
use App\Services\Servers\ServerManageSshExecutor;
use App\Services\Servers\ServerSystemdServicesCatalog;
use App\Support\ServerSystemdServiceNotificationKeys;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Systemd inventory from DB (filled by {@see SyncServerSystemdServicesJob}) and allowlisted start/stop/restart.
 *
 * @phpstan-require-extends Component
 */
trait ManagesServerSystemdServices
{
    public ?string $remote_error = null;

    /**
     * Persist the latest SSH/systemd error for UI that reads {@see $remote_error} and surface it as a toast.
     */
    protected function setSystemdRemoteError(?string $message): void
    {
        $this->remote_error = $message;
        if (is_string($message) && $message !== '') {
            $this->toastError($message);
        }
    }

    /**
     * When a queued systemd SSH task finishes, whether to dispatch {@see SyncServerSystemdServicesJob} (false for read-only status).
     * Public so it survives wire:poll round-trips — protected Livewire properties are reset on every request.
     */
    public ?bool $systemdQueueInventoryAfterRemoteTask = null;

    /**
     * @var list<array{unit: string, label: string, active: string, sub: string, ts: string, version: string, custom: bool, can_manage: bool}>
     */
    public array $systemdInventory = [];

    public ?string $systemdInventoryFetchedAt = null;

    /**
     * @var list<array{at: string, kind: string, unit: string, label: string, detail: ?string}>
     */
    public array $systemdServiceActivity = [];

    public ?string $systemdRemoteTaskId = null;

    /**
     * Checked unit names (normalized, e.g. nginx.service). Array-of-values checkboxes; do not use
     * associative keys — unit names contain dots.
     *
     * @var list<string>
     */
    public array $systemdSelectedList = [];

    public bool $systemdSelectAll = false;

    public string $newCustomSystemdUnit = '';

    public bool $showCustomSystemdModal = false;

    public bool $showSystemdStatusModal = false;

    public string $systemdStatusModalUnit = '';

    protected ?string $systemdStatusModalUnitNormalized = null;

    public string $systemdStatusModalOutput = '';

    public bool $systemdStatusModalLoading = false;

    public ?string $systemdStatusModalError = null;

    /** Which body the status modal is showing: 'status' (systemctl) or 'logs' (journalctl). */
    public string $systemdStatusModalView = 'status';

    public bool $showSystemdActionConfirm = false;

    /**
     * Kind drives icon, tone, copy. One of: 'start', 'restart', 'stop', 'reload', 'enable',
     * 'disable', 'bulk-restart', 'bulk-stop', 'remove-custom'.
     */
    public string $systemdActionConfirmKind = '';

    public string $systemdActionConfirmUnit = '';

    public function openSystemdActionConfirm(string $kind, ?string $unit = null): void
    {
        if (! in_array($kind, ['start', 'restart', 'stop', 'reload', 'enable', 'disable', 'bulk-restart', 'bulk-stop', 'remove-custom'], true)) {
            return;
        }
        $this->systemdActionConfirmKind = $kind;
        $this->systemdActionConfirmUnit = (string) $unit;
        $this->showSystemdActionConfirm = true;
    }

    public function closeSystemdActionConfirm(): void
    {
        $this->showSystemdActionConfirm = false;
        $this->systemdActionConfirmKind = '';
        $this->systemdActionConfirmUnit = '';
    }

    public function confirmSystemdAction(): void
    {
        $kind = $this->systemdActionConfirmKind;
        $unit = $this->systemdActionConfirmUnit;
        $this->closeSystemdActionConfirm();

        match (true) {
            $kind === 'bulk-restart' => $this->bulkSystemdRestart(),
            $kind === 'bulk-stop' => $this->bulkSystemdStop(),
            $kind === 'remove-custom' && $unit !== '' => $this->removeCustomSystemdUnit($unit),
            in_array($kind, ['start', 'restart', 'stop', 'reload', 'enable', 'disable'], true) && $unit !== ''
                => $this->runSystemdServiceAction($unit, $kind),
            default => null,
        };
    }

    /**
     * Locate the current inventory row for the unit being confirmed so the modal can render
     * its active state, PID, boot state, etc. Returns null when the modal is bulk or the unit
     * isn't in the cached inventory (e.g. fresh server with no sync yet).
     *
     * @return array<string, mixed>|null
     */
    public function systemdActionConfirmRow(): ?array
    {
        $unit = $this->systemdActionConfirmUnit;
        $kind = $this->systemdActionConfirmKind;
        if ($unit === '' || str_starts_with($kind, 'bulk-') || $kind === 'remove-custom') {
            return null;
        }

        foreach ($this->systemdInventory as $row) {
            if (($row['unit'] ?? '') === $unit) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The exact bash command the action will run over SSH — surfaced in the modal so the
     * operator sees what's about to happen before they confirm. Bulk returns a short preview
     * (first selected unit). Remove-custom is not an SSH action and returns null.
     */
    public function systemdActionConfirmCommand(): ?string
    {
        $kind = $this->systemdActionConfirmKind;
        $unit = $this->systemdActionConfirmUnit;

        if ($kind === '' || $kind === 'remove-custom') {
            return null;
        }

        if (in_array($kind, ['start', 'restart', 'stop', 'reload', 'enable', 'disable'], true) && $unit !== '') {
            return $this->systemdActionBash($unit, $kind);
        }

        if ($kind === 'bulk-restart' || $kind === 'bulk-stop') {
            $action = $kind === 'bulk-restart' ? 'restart' : 'stop';
            $first = $this->systemdSelectedList[0] ?? null;
            if (! is_string($first) || $first === '') {
                return null;
            }
            $count = count($this->systemdSelectedList);
            $preview = $this->systemdActionBash($first, $action);
            if ($count > 1) {
                $preview .= "\n# … and ".($count - 1).' more';
            }

            return $preview;
        }

        return null;
    }

    public bool $showSystemdNotifyModal = false;

    public string $systemdNotifyUnit = '';

    protected ?string $systemdNotifyUnitNormalized = null;

    /**
     * Channel id => which inventory-driven events notify that channel for the open unit.
     *
     * @var array<string, array{stopped: bool, started: bool, restarted: bool, state_changed: bool}>
     */
    public array $systemdNotifyMatrix = [];

    /**
     * @var list<array{id: string, label: string}>
     */
    public array $systemdNotifyChannelRows = [];

    /**
     * @var null|'action'|'status_modal'
     *
     * Public so it survives wire:poll — the cache-poll completion handler reads this to decide
     * whether to fire {@see finishSystemdActionBanner}. Protected would reset to null on every
     * Livewire request, leaving the action banner stuck on `queued`.
     */
    public ?string $systemdPendingKind = null;

    public ?string $systemdPendingActionUnit = null;

    public ?string $systemdRowBusyUnit = null;

    public bool $systemdBulkBusy = false;

    public string $systemdFilterSearch = '';

    public string $systemdFilterActive = 'all';

    public string $systemdFilterCustom = 'all';

    /**
     * When false, the services table hides background units the operator can't manage
     * (getty@tty1, ModemManager, multipathd, dbus, …). Failed units always render.
     * Toggled by the table footer "Show all services" button.
     */
    public bool $systemdShowSystem = false;

    public function toggleSystemdShowSystem(): void
    {
        $this->systemdShowSystem = ! $this->systemdShowSystem;
    }

    public string $services_workspace_tab = 'inventory';

    /**
     * Latest sync.at timestamp the operator dismissed; the inventory-sync banner stays hidden
     * for that exact run and re-arms automatically on the next sync (different `at`). Persisted
     * in the session so dismissal survives a full page reload — Livewire properties otherwise
     * reset on remount.
     */
    public ?string $systemdSyncBannerDismissedAt = null;

    public function dismissSystemdSyncBanner(): void
    {
        $at = (string) ($this->systemdInventorySyncMeta()['at'] ?? '');
        $this->systemdSyncBannerDismissedAt = $at;
        session([$this->systemdSyncBannerDismissSessionKey() => $at]);
    }

    public function hydrateSystemdSyncBannerDismissalFromSession(): void
    {
        $at = session($this->systemdSyncBannerDismissSessionKey());
        $this->systemdSyncBannerDismissedAt = is_string($at) && $at !== '' ? $at : null;
    }

    protected function systemdSyncBannerDismissSessionKey(): string
    {
        return 'systemd_sync_banner_dismissed_at:server:'.$this->server->id;
    }

    /**
     * Action banner: surfaces the most recent (or in-flight) systemctl action over SSH so the
     * operator can see queued/running/completed/failed status and the SSH transcript without
     * waiting for an inventory poll.
     */
    public string $systemdActionBannerKind = '';

    public string $systemdActionBannerUnit = '';

    public string $systemdActionBannerStatus = '';

    /**
     * @var list<string>
     */
    public array $systemdActionBannerLines = [];

    public ?string $systemdActionBannerError = null;

    public ?string $systemdActionBannerStartedAt = null;

    public ?string $systemdActionBannerFinishedAt = null;

    public function dismissSystemdActionBanner(): void
    {
        if (in_array($this->systemdActionBannerStatus, ['queued', 'running'], true)) {
            return;
        }
        $this->resetSystemdActionBanner();
    }

    protected function resetSystemdActionBanner(): void
    {
        $this->systemdActionBannerKind = '';
        $this->systemdActionBannerUnit = '';
        $this->systemdActionBannerStatus = '';
        $this->systemdActionBannerLines = [];
        $this->systemdActionBannerError = null;
        $this->systemdActionBannerStartedAt = null;
        $this->systemdActionBannerFinishedAt = null;
    }

    protected function startSystemdActionBanner(string $kind, string $unitLabel): void
    {
        $this->systemdActionBannerKind = $kind;
        $this->systemdActionBannerUnit = $unitLabel;
        $this->systemdActionBannerStatus = 'running';
        $this->systemdActionBannerLines = [];
        $this->systemdActionBannerError = null;
        $this->systemdActionBannerStartedAt = now()->toIso8601String();
        $this->systemdActionBannerFinishedAt = null;
    }

    protected function appendSystemdActionBannerOutput(string $buffer): void
    {
        if ($this->systemdActionBannerStatus === '') {
            return;
        }
        foreach (preg_split('/\r?\n/', rtrim($buffer, "\r\n")) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $this->systemdActionBannerLines[] = $line;
        }
        if (count($this->systemdActionBannerLines) > 200) {
            $this->systemdActionBannerLines = array_slice($this->systemdActionBannerLines, -200);
        }
    }

    protected function finishSystemdActionBanner(string $status, ?string $error = null): void
    {
        if ($this->systemdActionBannerStatus === '') {
            return;
        }
        $this->systemdActionBannerStatus = $status;
        $this->systemdActionBannerError = $error;
        $this->systemdActionBannerFinishedAt = now()->toIso8601String();
    }

    protected function systemdDeployerWorkspaceBlocked(): bool
    {
        if (! $this->currentUserIsDeployer()) {
            return false;
        }
        $org = $this->server->organization;

        return ! (bool) ($org?->mergedServicesPreferences()['deployer_systemd_actions_enabled'] ?? false);
    }

    protected function clearSystemdActionBusyState(): void
    {
        $this->systemdRowBusyUnit = null;
        $this->systemdPendingActionUnit = null;
        $this->systemdBulkBusy = false;
    }

    public function openCustomSystemdModal(): void
    {
        $this->showCustomSystemdModal = true;
    }

    public function closeCustomSystemdModal(): void
    {
        $this->showCustomSystemdModal = false;
    }

    /**
     * Open the Status + Logs modal for a unit and immediately fetch systemctl output over SSH.
     */
    public function openSystemdStatusModalForService(string $unit): void
    {
        $normalized = $this->validateUnitForModal($unit);
        if ($normalized === null) {
            return;
        }

        $this->showSystemdStatusModal = true;
        $this->systemdStatusModalUnit = $normalized;
        $this->systemdStatusModalUnitNormalized = $normalized;
        $this->systemdStatusModalError = null;

        // Inline SSH in this request (same as Refresh). Avoid $this->js() deferred calls — Livewire xjs scope
        // does not bind $wire, so deferred fetch often never ran and the modal stuck on “Fetching…”.
        $this->systemdStatusModalLoading = true;
        $this->systemdStatusModalOutput = '';
        $this->fillSystemdModalStatusFromRemoteSsh($normalized);
    }

    public function closeSystemdStatusModal(): void
    {
        $this->showSystemdStatusModal = false;
        $this->systemdStatusModalUnit = '';
        $this->systemdStatusModalUnitNormalized = null;
        $this->systemdStatusModalOutput = '';
        $this->systemdStatusModalLoading = false;
        $this->systemdStatusModalError = null;
        $this->systemdStatusModalView = 'status';
    }

    /**
     * Open the dedicated Notify modal (channels × event-kinds matrix) for a unit. No SSH fetch.
     */
    public function openSystemdNotifyModalForService(string $unit): void
    {
        $normalized = $this->validateUnitForModal($unit);
        if ($normalized === null) {
            return;
        }

        $this->showSystemdNotifyModal = true;
        $this->systemdNotifyUnit = $normalized;
        $this->systemdNotifyUnitNormalized = $normalized;
        $this->loadSystemdNotifyMatrix();
    }

    public function closeSystemdNotifyModal(): void
    {
        $this->showSystemdNotifyModal = false;
        $this->systemdNotifyUnit = '';
        $this->systemdNotifyUnitNormalized = null;
        $this->systemdNotifyMatrix = [];
        $this->systemdNotifyChannelRows = [];
    }

    /**
     * Shared validation/authorization for both modal openers; returns the normalized unit name or
     * null when the modal should not open (an error has been surfaced as a toast).
     */
    protected function validateUnitForModal(string $unit): ?string
    {
        $this->authorize('update', $this->server);
        $this->remote_error = null;

        if ($this->systemdDeployerWorkspaceBlocked()) {
            $this->setSystemdRemoteError(__('Deployers cannot control services on servers.'));

            return null;
        }

        if (! $this->serverOpsReady()) {
            $this->setSystemdRemoteError(__('Provisioning and SSH must be ready before running actions.'));

            return null;
        }

        try {
            return app(ServerSystemdServicesCatalog::class)->assertSafeUnitNameForStatus($unit);
        } catch (\InvalidArgumentException $e) {
            $this->setSystemdRemoteError($e->getMessage());

            return null;
        }
    }

    /**
     * Open the Status modal in "Logs" mode (journalctl).
     */
    public function openSystemdLogsModalForService(string $unit): void
    {
        $this->systemdStatusModalView = 'logs';
        $this->openSystemdStatusModalForService($unit);
    }

    /**
     * Toggle between Status and Logs without closing the modal.
     */
    public function setSystemdStatusModalView(string $view): void
    {
        if (! in_array($view, ['status', 'logs'], true)) {
            return;
        }
        $this->systemdStatusModalView = $view;
        $this->fetchSystemdModalStatus();
    }

    /**
     * Re-fetch systemctl status for the open modal (Refresh button).
     */
    public function fetchSystemdModalStatus(): void
    {
        $this->authorize('update', $this->server);

        if (! $this->showSystemdStatusModal) {
            return;
        }

        $normalized = $this->systemdStatusModalUnitNormalized;
        if ($normalized === null || $normalized === '') {
            return;
        }

        if ($this->systemdDeployerWorkspaceBlocked()) {
            $this->systemdStatusModalLoading = false;
            $this->systemdStatusModalError = __('Deployers cannot control services on servers.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->systemdStatusModalLoading = false;
            $this->systemdStatusModalError = __('Provisioning and SSH must be ready before running actions.');

            return;
        }

        try {
            app(ServerSystemdServicesCatalog::class)->assertSafeUnitNameForStatus($normalized);
        } catch (\InvalidArgumentException $e) {
            $this->systemdStatusModalLoading = false;
            $this->systemdStatusModalError = $e->getMessage();

            return;
        }

        $this->systemdStatusModalLoading = true;
        $this->systemdStatusModalError = null;
        $this->systemdStatusModalOutput = '';
        $this->fillSystemdModalStatusFromRemoteSsh($normalized);
    }

    /**
     * Runs systemctl status over SSH and writes {@see systemdStatusModalOutput} (never queued).
     */
    protected function fillSystemdModalStatusFromRemoteSsh(string $normalized): void
    {
        $view = $this->systemdStatusModalView === 'logs' ? 'logs' : 'status';
        $script = $this->systemdActionBash($normalized, $view);

        set_time_limit((int) config('server_services.systemd_action_timeout', 180) + 30);
        $timeout = (int) config('server_services.systemd_action_timeout', 180);

        try {
            $server = $this->server->fresh();
            $out = $this->runManageInlineBash(
                $server,
                'services-systemd:'.$normalized.':'.$view,
                $script,
                static function (string $type, string $buffer): void {},
                $timeout,
            );

            if (! $this->showSystemdStatusModal) {
                return;
            }

            $this->systemdStatusModalOutput = trim(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));
            $this->systemdStatusModalLoading = false;
            $this->systemdStatusModalError = null;
        } catch (\Throwable $e) {
            if ($this->showSystemdStatusModal) {
                $this->systemdStatusModalLoading = false;
                $this->systemdStatusModalError = $e->getMessage();
            }
        }
    }

    public function saveSystemdNotifyPreferences(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change notification routing.'));

            return;
        }

        $unit = $this->systemdNotifyUnitNormalized;
        if ($unit === null || $unit === '') {
            return;
        }

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        $channels = AssignableNotificationChannels::forUser($user, $user->currentOrganization());
        $allowedIds = $channels->pluck('id')->map(fn ($id) => (string) $id)->all();

        DB::transaction(function () use ($unit, $channels, $allowedIds): void {
            foreach ($channels as $channel) {
                $cid = (string) $channel->id;
                if (! in_array($cid, $allowedIds, true)) {
                    continue;
                }
                if (! Gate::allows('manageNotificationChannels', $channel->owner)) {
                    continue;
                }
                $row = $this->systemdNotifyMatrix[$cid] ?? [];
                foreach (ServerSystemdServiceNotificationKeys::KINDS as $kind) {
                    $wanted = (bool) ($row[$kind] ?? false);
                    $eventKey = ServerSystemdServiceNotificationKeys::eventKey($unit, $kind);
                    $q = NotificationSubscription::query()
                        ->where('notification_channel_id', $channel->id)
                        ->where('subscribable_type', Server::class)
                        ->where('subscribable_id', $this->server->id)
                        ->where('event_key', $eventKey);
                    if ($wanted) {
                        NotificationSubscription::query()->firstOrCreate([
                            'notification_channel_id' => $channel->id,
                            'subscribable_type' => Server::class,
                            'subscribable_id' => $this->server->id,
                            'event_key' => $eventKey,
                        ]);
                    } else {
                        $q->delete();
                    }
                }
            }
        });

        $this->toastSuccess(__('Alert preferences saved.'));
        $this->loadSystemdNotifyMatrix();
        $this->hydrateSystemdInventoryFromDatabase();
    }

    protected function loadSystemdNotifyMatrix(): void
    {
        $unit = $this->systemdNotifyUnitNormalized;
        $this->systemdNotifyMatrix = [];
        $this->systemdNotifyChannelRows = [];
        if ($unit === null || $unit === '') {
            return;
        }

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        $channels = AssignableNotificationChannels::forUser($user, $user->currentOrganization());
        foreach ($channels as $channel) {
            $cid = (string) $channel->id;
            $entry = [
                'stopped' => false,
                'started' => false,
                'restarted' => false,
                'state_changed' => false,
            ];
            $anySubscribed = false;
            foreach (ServerSystemdServiceNotificationKeys::KINDS as $kind) {
                $eventKey = ServerSystemdServiceNotificationKeys::eventKey($unit, $kind);
                $on = NotificationSubscription::query()
                    ->where('notification_channel_id', $channel->id)
                    ->where('subscribable_type', Server::class)
                    ->where('subscribable_id', $this->server->id)
                    ->where('event_key', $eventKey)
                    ->exists();
                $entry[$kind] = $on;
                $anySubscribed = $anySubscribed || $on;
            }
            if (! $anySubscribed) {
                $entry['stopped'] = true;
                $entry['state_changed'] = true;
                $entry['started'] = false;
                $entry['restarted'] = false;
            }
            $this->systemdNotifyMatrix[$cid] = $entry;
        }

        $this->systemdNotifyChannelRows = $channels
            ->map(fn ($c) => ['id' => (string) $c->id, 'label' => (string) $c->label])
            ->values()
            ->all();
    }

    public function updatedSystemdSelectAll(bool $value): void
    {
        if (! $value) {
            $this->systemdSelectedList = [];

            return;
        }
        $manageable = [];
        foreach ($this->systemdInventory as $row) {
            if (! empty($row['may_mutate'])) {
                $manageable[] = $row['unit'];
            }
        }
        $this->systemdSelectedList = $manageable;
    }

    public function updatedSystemdSelectedList(): void
    {
        $manageable = [];
        foreach ($this->systemdInventory as $row) {
            if (! empty($row['may_mutate'])) {
                $manageable[] = $row['unit'];
            }
        }
        $n = count($manageable);
        $sel = count(array_intersect($manageable, $this->systemdSelectedList));
        $this->systemdSelectAll = $n > 0 && $sel === $n;
    }

    /**
     * Load running-service rows written by {@see SyncServerSystemdServicesJob}.
     */
    public function hydrateSystemdInventoryFromDatabase(): void
    {
        $states = ServerSystemdServiceState::query()
            ->where('server_id', $this->server->id)
            ->orderBy('label')
            ->get();

        if ($states->isEmpty()) {
            $this->systemdInventory = [];
            $this->systemdInventoryFetchedAt = null;

            return;
        }

        $countsBySlug = ServerSystemdServiceNotificationKeys::alertSubscriptionCountsBySlug($this->server);
        $catalog = app(ServerSystemdServicesCatalog::class);

        $deployerBlocked = $this->systemdDeployerWorkspaceBlocked();

        $this->systemdInventory = $states->map(function (ServerSystemdServiceState $s) use ($countsBySlug, $catalog, $deployerBlocked) {
            $slug = ServerSystemdServiceNotificationKeys::slugFromUnit($s->unit);
            $statusOnly = $catalog->isUnitStatusOnlyForServer($this->server, $s->unit);
            $isFailed = $s->active_state === 'failed' || $s->sub_state === 'failed';

            // Pending action expires after ~3 min so a stuck row clears
            // even if the inventory sync never lands. Anything fresher
            // is surfaced to the blade so it can render "Starting…"
            // / "Stopping…" / etc. instead of stale active_state.
            $pendingAction = (string) ($s->pending_action ?? '');
            $pendingFresh = $pendingAction !== ''
                && $s->pending_action_at !== null
                && $s->pending_action_at->isAfter(now()->subMinutes(3));

            return [
                'unit' => $s->unit,
                'label' => $s->label,
                'active' => $s->active_state,
                'sub' => $s->sub_state,
                'ts' => (string) ($s->active_enter_ts ?? ''),
                'version' => $s->version,
                'custom' => $s->is_custom,
                'can_manage' => $s->can_manage,
                'may_mutate' => $s->can_manage && ! $statusOnly && ! $deployerBlocked,
                'status_only' => $statusOnly,
                'is_failed' => $isFailed,
                'boot_state' => (string) ($s->unit_file_state ?? ''),
                'main_pid' => (string) ($s->main_pid ?? ''),
                'pending_action' => $pendingFresh ? $pendingAction : null,
                'boot_likely_enabled' => $catalog->bootStateLikelyEnabled($s->unit_file_state),
                'boot_likely_disabled' => $catalog->bootStateLikelyDisabled($s->unit_file_state),
                'boot_menu_show_enable' => $catalog->bootMenuShowEnableAtBoot($s->unit_file_state),
                'boot_menu_show_disable' => $catalog->bootMenuShowDisableAtBoot($s->unit_file_state),
                'alert_subscription_count' => $countsBySlug[$slug] ?? 0,
                'inline_disable_at_boot' => $catalog->shouldOfferInlineDisableAtBoot($s->unit),
            ];
        })->all();

        $max = $states->max('captured_at');
        $this->systemdInventoryFetchedAt = $max instanceof \DateTimeInterface
            ? $max->format(\DateTimeInterface::ATOM)
            : null;
    }

    public function hydrateSystemdServiceActivityFromDatabase(): void
    {
        $limit = max(10, (int) config('server_services.systemd_services_activity_max_events', 75));
        $rows = ServerSystemdServiceAuditEvent::query()
            ->where('server_id', $this->server->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $this->systemdServiceActivity = $rows->map(function (ServerSystemdServiceAuditEvent $e) {
            return [
                'at' => $e->occurred_at->toIso8601String(),
                'kind' => $e->kind,
                'unit' => $e->unit,
                'label' => $e->label,
                'detail' => $e->detail,
            ];
        })->all();
    }

    /**
     * Poll from the browser to pick up rows after the queue worker finishes (no SSH in the request).
     */
    public function refreshSystemdUiFromDatabase(): void
    {
        $this->server->refresh();
        $this->hydrateSystemdInventoryFromDatabase();
        $this->hydrateSystemdServiceActivityFromDatabase();
    }

    /**
     * Queue SSH inventory; workers persist to the database.
     *
     * @param  bool  $silent  When true (auto-refresh on load), skip the toast and the banner; the
     *                       page already shows the cached inventory and we don't want to surprise
     *                       the operator with a banner they didn't trigger.
     */
    protected function queueSystemdInventorySync(bool $silent): void
    {
        $this->authorize('update', $this->server);
        $this->remote_error = null;

        if ($this->currentUserIsDeployer()) {
            $this->setSystemdRemoteError(__('Deployers cannot sync services on servers.'));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->setSystemdRemoteError(__('Provisioning and SSH must be ready before syncing services.'));

            return;
        }

        if (! (bool) config('server_services.systemd_inventory_job_enabled', true)) {
            $this->setSystemdRemoteError(__('Service sync jobs are disabled in configuration.'));

            return;
        }

        if ($silent) {
            // Auto-load path: dispatch with no banner state, no cache, no broadcast. Explicit
            // dedupeKey so rapid page reloads/back-button visits coalesce to one job. The poll-
            // based meta banner picks up the result after completion.
            SyncServerSystemdServicesJob::dispatch(
                $this->server->id,
                null,
                null,
                'server-systemd-inventory:auto:'.$this->server->id,
            );

            return;
        }

        // Reject double-clicks: if the action banner is already showing an in-flight inventory
        // sync, do nothing. Without this, a second click would write a fresh `queued` entry but
        // the dispatched job's writeBannerCache would target the new key — UX-confusing.
        if (
            $this->systemdActionBannerKind === 'inventory-sync'
            && in_array($this->systemdActionBannerStatus, ['queued', 'running'], true)
        ) {
            $this->toastError(__('An inventory sync is already running. Wait for it to finish.'));

            return;
        }

        // Operator-initiated "Sync now": route through the same cache-tracked + broadcasted
        // banner machinery as Restart/Stop/etc. so the operator sees a persistent banner with
        // streaming SSH output instead of a dispatch-blip spinner.
        $id = (string) Str::uuid();
        $ttl = (int) config('server_manage.remote_task_cache_ttl_seconds', 900);

        Cache::put(ServerManageRemoteSshJob::cacheKey($id), [
            'status' => 'queued',
            'output' => '',
            'error' => null,
            'flash_success' => null,
            'queued_at' => time(),
        ], now()->addSeconds(max(120, $ttl)));

        $this->systemdPendingKind = 'action';
        $this->systemdQueueInventoryAfterRemoteTask = false;
        $this->systemdRemoteTaskId = $id;
        $this->startSystemdActionBanner('inventory-sync', __('inventory'));
        $this->systemdActionBannerStatus = 'queued';

        SyncServerSystemdServicesJob::dispatch(
            $this->server->id,
            $id,
            \App\Events\Servers\ServerSystemdActionCompletedBroadcast::class,
        );

        $this->js('window.__dplySystemdActionActiveId = '.json_encode($id).';');
        $this->toastSuccess(__('Service sync queued. The list will refresh automatically while you stay on this page.'));
    }

    public function refreshSystemdInventory(): void
    {
        $this->queueSystemdInventorySync(false);
    }

    /**
     * Called via {@code wire:init} to queue a sync when the snapshot in the database is stale or empty.
     */
    public function maybeRefreshSystemdInventoryOnLoad(): void
    {
        if (! (bool) config('server_services.systemd_inventory_refresh_on_load', true)) {
            return;
        }

        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }

        if (! auth()->user()?->can('update', $this->server)) {
            return;
        }

        if (! (bool) config('server_services.systemd_inventory_job_enabled', true)) {
            return;
        }

        $minAge = (int) config('server_services.systemd_inventory_skip_auto_refresh_if_newer_than_seconds', 45);
        if ($minAge > 0) {
            $latest = ServerSystemdServiceState::query()
                ->where('server_id', $this->server->id)
                ->max('captured_at');
            if ($latest !== null && Carbon::parse($latest)->greaterThan(now()->subSeconds($minAge))) {
                return;
            }
        }

        $this->queueSystemdInventorySync(true);
    }

    /**
     * @param  'start'|'stop'|'restart'|'reload'|'disable'|'enable'  $action
     */
    public function runSystemdServiceAction(string $unit, string $action): void
    {
        $this->authorize('update', $this->server);
        $this->remote_error = null;

        if ($this->systemdDeployerWorkspaceBlocked()) {
            $this->setSystemdRemoteError(__('Deployers cannot control services on servers.'));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->setSystemdRemoteError(__('Provisioning and SSH must be ready before running actions.'));

            return;
        }

        $allowedActions = ['start', 'stop', 'restart', 'reload', 'disable', 'enable'];
        if (! in_array($action, $allowedActions, true)) {
            $this->setSystemdRemoteError(__('Unknown action.'));

            return;
        }

        try {
            $normalized = app(ServerSystemdServicesCatalog::class)->assertAllowedOnServer($this->server->fresh(), $unit);
        } catch (\InvalidArgumentException $e) {
            $this->setSystemdRemoteError($e->getMessage());

            return;
        }

        $catalog = app(ServerSystemdServicesCatalog::class);
        if ($catalog->isUnitStatusOnlyForServer($this->server->fresh(), $normalized)) {
            $this->setSystemdRemoteError(__('This unit is status-only for your organization. Inspect it with Status; mutating actions are disabled.'));

            return;
        }

        $script = $this->systemdActionBash($normalized, $action);
        $this->systemdPendingKind = 'action';
        $this->systemdRowBusyUnit = $normalized;
        $this->systemdPendingActionUnit = $normalized;
        $this->startSystemdActionBanner($action, $normalized);

        // Record the operator's intent on the state row so the table can
        // immediately render "Starting…" / "Stopping…" / etc. The next
        // inventory sync will clear this when it confirms the actual
        // active_state. A safety expiry inside the renderer auto-clears
        // stale rows (~3 min) if SSH fails to re-sync for any reason.
        ServerSystemdServiceState::query()
            ->where('server_id', $this->server->id)
            ->where('unit', $normalized)
            ->update([
                'pending_action' => $action,
                'pending_action_at' => now(),
            ]);

        set_time_limit((int) config('server_services.systemd_action_timeout', 180) + 30);
        $timeout = (int) config('server_services.systemd_action_timeout', 180);

        try {
            $server = $this->server->fresh();
            $flash = match ($action) {
                'reload' => __('Reload finished.'),
                'disable' => __('Disable at boot finished. The service may keep running until it is stopped.'),
                'enable' => __('Enable at boot finished.'),
                default => __('Service action finished.'),
            };
            $syncInventoryAfter = true;

            if ($this->shouldQueueManageRemoteTasks()) {
                $this->dispatchQueuedSystemdScript(
                    $server,
                    'services-systemd:'.$normalized.':'.$action,
                    $script,
                    $timeout,
                    $flash,
                    $syncInventoryAfter,
                );
                $this->systemdActionBannerStatus = 'queued';

                if ($server->organization) {
                    audit_log($server->organization, auth()->user(), 'server.service.'.$action, $server, null, [
                        'unit' => $normalized,
                        'queued' => true,
                    ]);
                }

                return;
            }

            $out = $this->runManageInlineBash(
                $server,
                'services-systemd:'.$normalized.':'.$action,
                $script,
                static function (string $type, string $buffer): void {},
                $timeout,
            );
            $this->appendSystemdActionBannerOutput(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));
            $this->finishSystemdActionBanner('completed');
            $this->toastSuccess($flash);
            $this->remote_error = null;
            $this->clearPendingActionAndRehydrate();
            if ($server->organization) {
                audit_log($server->organization, auth()->user(), 'server.service.'.$action, $server, null, [
                    'unit' => $normalized,
                    'result' => 'success',
                ]);
            }
            if ($syncInventoryAfter && (bool) config('server_services.systemd_inventory_job_enabled', true)) {
                SyncServerSystemdServicesJob::dispatch($this->server->id);
            }
        } catch (\Throwable $e) {
            $this->finishSystemdActionBanner('failed', $e->getMessage());
            $this->setSystemdRemoteError($e->getMessage());
            $this->clearPendingActionAndRehydrate();
            if ($this->server->organization) {
                audit_log($this->server->organization, auth()->user(), 'server.service.'.$action, $this->server, null, [
                    'unit' => $normalized,
                    'result' => 'failed',
                    'error' => mb_strimwidth($e->getMessage(), 0, 500),
                ]);
            }
        } finally {
            if (! $this->shouldQueueManageRemoteTasks()) {
                $this->clearSystemdActionBusyState();
            }
        }
    }

    public function bulkSystemdRestart(): void
    {
        $this->runBulkSystemd('restart');
    }

    public function bulkSystemdStop(): void
    {
        $this->runBulkSystemd('stop');
    }

    /**
     * @param  'restart'|'stop'  $action
     */
    protected function runBulkSystemd(string $action): void
    {
        $units = array_values(array_unique($this->systemdSelectedList));
        if ($units === []) {
            $this->toastError(__('Select at least one service.'));

            return;
        }
        $catalog = app(ServerSystemdServicesCatalog::class);
        $normalized = [];
        foreach ($units as $u) {
            try {
                $normalized[] = $catalog->assertAllowedOnServer($this->server->fresh(), $u);
            } catch (\InvalidArgumentException $e) {
                $this->toastError($e->getMessage());

                return;
            }
        }
        $normalized = array_unique($normalized);
        foreach ($normalized as $u) {
            if ($catalog->isUnitStatusOnlyForServer($this->server->fresh(), $u)) {
                $this->toastError(__('One or more selected units are status-only and cannot be changed from here.'));

                return;
            }
        }
        $script = implode("\n", array_map(
            fn (string $u) => $this->systemdActionBash($u, $action)."\n",
            $normalized
        ))."exit 0\n";

        $this->authorize('update', $this->server);
        $this->remote_error = null;

        if ($this->systemdDeployerWorkspaceBlocked()) {
            $this->setSystemdRemoteError(__('Deployers cannot control services on servers.'));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->setSystemdRemoteError(__('Provisioning and SSH must be ready before running actions.'));

            return;
        }

        $this->systemdPendingKind = 'action';
        $this->systemdBulkBusy = true;
        $bulkLabel = trans_choice(':count selected unit|:count selected units', count($normalized), ['count' => count($normalized)]);
        $this->startSystemdActionBanner('bulk-'.$action, (string) $bulkLabel);
        $timeout = max(60, count($normalized) * (int) config('server_services.systemd_action_timeout', 180));

        try {
            $server = $this->server->fresh();
            $flash = __('Bulk service action finished.');

            if ($this->shouldQueueManageRemoteTasks()) {
                $this->dispatchQueuedSystemdScript(
                    $server,
                    'services-systemd-bulk:'.$action,
                    $script,
                    $timeout,
                    $flash,
                    true,
                );
                $this->systemdActionBannerStatus = 'queued';

                return;
            }

            $out = $this->runManageInlineBash(
                $server,
                'services-systemd-bulk:'.$action,
                $script,
                static function (string $type, string $buffer): void {},
                $timeout,
            );
            $this->appendSystemdActionBannerOutput(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));
            $this->finishSystemdActionBanner('completed');
            $this->toastSuccess($flash);
            $this->clearPendingActionAndRehydrate();
            if ($server->organization) {
                audit_log($server->organization, auth()->user(), 'server.service.bulk_'.$action, $server, null, [
                    'units' => $normalized,
                    'count' => count($normalized),
                    'result' => 'success',
                ]);
            }
            if ((bool) config('server_services.systemd_inventory_job_enabled', true)) {
                SyncServerSystemdServicesJob::dispatch($this->server->id);
            }
        } catch (\Throwable $e) {
            $this->finishSystemdActionBanner('failed', $e->getMessage());
            $this->setSystemdRemoteError($e->getMessage());
            $this->clearPendingActionAndRehydrate();
            if ($this->server->organization) {
                audit_log($this->server->organization, auth()->user(), 'server.service.bulk_'.$action, $this->server, null, [
                    'units' => $normalized,
                    'count' => count($normalized),
                    'result' => 'failed',
                    'error' => mb_strimwidth($e->getMessage(), 0, 500),
                ]);
            }
        } finally {
            if (! $this->shouldQueueManageRemoteTasks()) {
                $this->clearSystemdActionBusyState();
            }
        }
    }

    public function addCustomSystemdUnit(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change custom services.'));

            return;
        }

        try {
            $normalized = app(ServerSystemdServicesCatalog::class)->validateAndNormalizeCustomUnit($this->newCustomSystemdUnit);
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $meta = $this->server->meta ?? [];
        $list = $meta['custom_systemd_services'] ?? [];
        if (! is_array($list)) {
            $list = [];
        }
        $strings = [];
        foreach ($list as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }
        if (in_array($normalized, $strings, true)) {
            $this->toastError(__('That unit is already listed.'));

            return;
        }
        $strings[] = $normalized;
        $meta['custom_systemd_services'] = array_values(array_unique($strings));
        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->newCustomSystemdUnit = '';
        if ((bool) config('server_services.systemd_inventory_job_enabled', true)) {
            SyncServerSystemdServicesJob::dispatch($this->server->id);
        }
        $this->toastSuccess(__('Custom unit saved. A background sync will refresh the list when the worker runs.'));
    }

    public function removeCustomSystemdUnit(string $unit): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change custom services.'));

            return;
        }

        try {
            $normalized = app(ServerSystemdServicesCatalog::class)->validateAndNormalizeCustomUnit($unit);
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $meta = $this->server->meta ?? [];
        $list = $meta['custom_systemd_services'] ?? [];
        if (! is_array($list)) {
            $list = [];
        }
        $strings = [];
        foreach ($list as $item) {
            if (is_string($item) && $item !== '' && $this->normalizeUnitStatic($item) !== $normalized) {
                $strings[] = $item;
            }
        }
        $meta['custom_systemd_services'] = array_values($strings);
        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        if ((bool) config('server_services.systemd_inventory_job_enabled', true)) {
            SyncServerSystemdServicesJob::dispatch($this->server->id);
        }
        $this->toastSuccess(__('Custom unit removed. A background sync will refresh the list when the worker runs.'));
    }

    public function isCustomSystemdUnit(string $normalizedUnit): bool
    {
        $meta = $this->server->meta ?? [];
        $list = $meta['custom_systemd_services'] ?? [];
        if (! is_array($list)) {
            return false;
        }
        foreach ($list as $item) {
            if (! is_string($item)) {
                continue;
            }
            if ($this->normalizeUnitStatic($item) === $normalizedUnit) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reverb-driven fast path: the bootstrap.js Echo binder dispatches this Livewire event when a
     * `.server.systemd.action.completed` broadcast arrives for the active task id. We just call
     * the existing cache-poll path — the broadcast fires *after* the cache write, so the cache
     * already says finished/failed. Variadic payload mirrors {@see WorkspaceCron::onCronRunFinished}
     * so we accept both `(array)` and `(runId, success, ...)` Livewire dispatch shapes.
     */
    #[On('systemd-action-completed')]
    public function onSystemdActionCompletedBroadcast(mixed ...$payload): void
    {
        $runId = '';
        $first = $payload[0] ?? null;
        if (is_array($first)) {
            $runId = (string) ($first['runId'] ?? $first['run_id'] ?? '');
        } elseif (is_string($first)) {
            $runId = $first;
        }

        if ($runId === '' || $runId !== (string) ($this->systemdRemoteTaskId ?? '')) {
            return;
        }

        $this->syncSystemdRemoteTaskFromCache();
    }

    public function syncSystemdRemoteTaskFromCache(): void
    {
        if ($this->systemdRemoteTaskId === null || $this->systemdRemoteTaskId === '') {
            return;
        }

        $payload = Cache::get(ServerManageRemoteSshJob::cacheKey($this->systemdRemoteTaskId));
        if (! is_array($payload)) {
            return;
        }

        $status = (string) ($payload['status'] ?? '');
        $out = (string) ($payload['output'] ?? '');
        $queuedAt = isset($payload['queued_at']) && is_numeric($payload['queued_at'])
            ? (int) $payload['queued_at']
            : null;
        $stalledAfter = (int) config('server_manage.remote_task_stalled_queued_seconds', 45);
        $stalledQueued = $status === 'queued'
            && $queuedAt !== null
            && (time() - $queuedAt) > $stalledAfter;

        $statusHint = match ($status) {
            'queued' => $stalledQueued
                ? __('Still preparing this task. If it stays stuck, contact your administrator.')
                : __('Task queued…'),
            'running' => __('Running on server…'),
            default => '',
        };

        $err = $payload['error'] ?? null;
        $this->setSystemdRemoteError(is_string($err) && $err !== '' ? $err : null);

        $pendingKind = $this->systemdPendingKind;
        if ($pendingKind === 'status_modal') {
            $this->systemdStatusModalOutput = $out !== ''
                ? $out
                : $statusHint;
            $this->systemdStatusModalLoading = ! in_array($status, ['finished', 'failed'], true);
            if ($this->remote_error !== null) {
                $this->systemdStatusModalError = $this->remote_error;
            }
        }

        if ($pendingKind === 'action' && $this->systemdActionBannerStatus !== '') {
            // Mirror the queued/running cache state into the action banner so the operator
            // sees live progress without an inventory poll.
            $this->systemdActionBannerStatus = match ($status) {
                'queued' => 'queued',
                'running' => 'running',
                default => $this->systemdActionBannerStatus,
            };
            if ($out !== '') {
                $this->systemdActionBannerLines = [];
                $this->appendSystemdActionBannerOutput($out);
            }
        }

        if (! in_array($status, ['finished', 'failed'], true)) {
            return;
        }

        if ($pendingKind === 'status_modal') {
            $this->systemdStatusModalLoading = false;
            if ($status === 'finished') {
                $this->systemdStatusModalOutput = trim($out !== '' ? $out : $this->systemdStatusModalOutput);
                $this->systemdStatusModalError = null;
                $this->remote_error = null;
            } else {
                $this->systemdStatusModalError = $this->remote_error ?? __('Remote command failed.');
            }

            Cache::forget(ServerManageRemoteSshJob::cacheKey($this->systemdRemoteTaskId));
            $this->systemdRemoteTaskId = null;
            $this->systemdPendingKind = null;
            $this->systemdQueueInventoryAfterRemoteTask = null;
            $this->remote_error = null;

            return;
        }

        $shouldSyncInventory = (bool) ($this->systemdQueueInventoryAfterRemoteTask ?? false);

        if ($status === 'finished' && $pendingKind === 'action') {
            $flash = $payload['flash_success'] ?? null;
            if ($out !== '' && $this->systemdActionBannerStatus !== '') {
                $this->systemdActionBannerLines = [];
                $this->appendSystemdActionBannerOutput($out);
            }
            $this->finishSystemdActionBanner('completed');
            $this->toastSuccess(is_string($flash) && $flash !== ''
                ? $flash
                : __('Service action finished.'));
            $this->remote_error = null;
            $this->clearPendingActionAndRehydrate();
            if ($shouldSyncInventory && (bool) config('server_services.systemd_inventory_job_enabled', true)) {
                SyncServerSystemdServicesJob::dispatch($this->server->id);
            }
        } elseif ($status === 'failed' && $pendingKind === 'action') {
            if ($out !== '' && $this->systemdActionBannerStatus !== '') {
                $this->systemdActionBannerLines = [];
                $this->appendSystemdActionBannerOutput($out);
            }
            $this->finishSystemdActionBanner('failed', is_string($err) && $err !== '' ? $err : null);
            $this->clearPendingActionAndRehydrate();
        }

        Cache::forget(ServerManageRemoteSshJob::cacheKey($this->systemdRemoteTaskId));
        $this->systemdRemoteTaskId = null;
        $this->systemdPendingKind = null;
        $this->systemdQueueInventoryAfterRemoteTask = null;
        $this->clearSystemdActionBusyState();
    }

    /**
     * Defense-in-depth: clear the `pending_action` flag on the unit row(s) the operator just
     * acted on and re-hydrate the in-memory inventory in the same Livewire request. Without
     * this, the row stays stuck on "Restarting…" / "Stopping…" until the post-action
     * SyncServerSystemdServicesJob runs and the wire:poll.5s picks up the fresh state — a
     * window of several seconds, longer if the queue worker is slow.
     */
    protected function clearPendingActionAndRehydrate(): void
    {
        $unit = $this->systemdPendingActionUnit;

        $q = ServerSystemdServiceState::query()->where('server_id', $this->server->id);
        if ($unit !== null && $unit !== '') {
            $q->where('unit', $unit);
        } else {
            // Bulk path: we don't track a single unit; clear pending state for everything we
            // marked when starting the bulk action. Cheap on a per-server table.
            $q->whereNotNull('pending_action');
        }

        $q->update([
            'pending_action' => null,
            'pending_action_at' => null,
        ]);

        $this->hydrateSystemdInventoryFromDatabase();
    }

    /**
     * Count of system rows hidden by the "Show all services" toggle. Used by the
     * services table footer to label the "Show N system services" disclosure.
     */
    public function systemdHiddenSystemCount(): int
    {
        if ($this->systemdShowSystem) {
            return 0;
        }

        return count(array_filter(
            $this->systemdInventory,
            fn (array $r): bool => empty($r['can_manage']) && empty($r['is_failed']),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function systemdFilteredInventoryRows(): array
    {
        $rows = $this->systemdInventory;
        $q = trim($this->systemdFilterSearch);
        if ($q !== '') {
            $qLower = strtolower($q);
            $rows = array_values(array_filter($rows, function (array $r) use ($qLower): bool {
                $hay = strtolower(($r['unit'] ?? '').' '.($r['label'] ?? ''));

                return str_contains($hay, $qLower);
            }));
        }

        $f = $this->systemdFilterActive;
        if ($f === 'active') {
            $rows = array_values(array_filter($rows, fn (array $r): bool => ($r['active'] ?? '') === 'active'));
        } elseif ($f === 'inactive') {
            $rows = array_values(array_filter($rows, fn (array $r): bool => ($r['active'] ?? '') !== 'active'));
        } elseif ($f === 'failed') {
            $rows = array_values(array_filter($rows, fn (array $r): bool => ! empty($r['is_failed'])));
        }

        $c = $this->systemdFilterCustom;
        if ($c === 'custom') {
            $rows = array_values(array_filter($rows, fn (array $r): bool => ! empty($r['custom'])));
        } elseif ($c === 'default') {
            $rows = array_values(array_filter($rows, fn (array $r): bool => empty($r['custom'])));
        }

        if (! $this->systemdShowSystem) {
            $rows = array_values(array_filter(
                $rows,
                fn (array $r): bool => ! empty($r['can_manage']) || ! empty($r['is_failed']),
            ));
        }

        usort($rows, function (array $a, array $b): int {
            $fa = ! empty($a['is_failed']) ? 0 : 1;
            $fb = ! empty($b['is_failed']) ? 0 : 1;
            if ($fa !== $fb) {
                return $fa <=> $fb;
            }

            return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
        });

        return $rows;
    }

    /**
     * @return array{at: ?string, status: ?string, error: ?string, duration_ms: ?int}
     */
    public function systemdInventorySyncMeta(): array
    {
        $meta = $this->server->meta ?? [];

        return [
            'at' => isset($meta['systemd_inventory_last_at']) && is_string($meta['systemd_inventory_last_at'])
                ? $meta['systemd_inventory_last_at']
                : null,
            'status' => isset($meta['systemd_inventory_last_status']) && is_string($meta['systemd_inventory_last_status'])
                ? $meta['systemd_inventory_last_status']
                : null,
            'error' => isset($meta['systemd_inventory_last_error']) && is_string($meta['systemd_inventory_last_error'])
                ? $meta['systemd_inventory_last_error']
                : null,
            'duration_ms' => isset($meta['systemd_inventory_last_duration_ms']) && is_numeric($meta['systemd_inventory_last_duration_ms'])
                ? (int) $meta['systemd_inventory_last_duration_ms']
                : null,
        ];
    }

    public function retrySystemdInventorySyncFromBanner(): void
    {
        $this->queueSystemdInventorySync(false);
    }

    protected function systemdActionBash(string $normalizedUnit, string $action): string
    {
        $u = escapeshellarg($normalizedUnit);

        return match ($action) {
            'status' => '(systemctl status '.$u.' --no-pager -l 2>&1); exit 0',
            'logs' => '(journalctl --no-pager --output=short-iso -u '.$u.' -n 200 2>&1); exit 0',
            'start', 'stop', 'restart', 'reload', 'disable', 'enable' => '(sudo -n systemctl '.$action.' '.$u.' || systemctl '.$action.' '.$u.') 2>&1',
            default => throw new \InvalidArgumentException,
        };
    }

    protected function dispatchQueuedSystemdScript(
        Server $server,
        string $taskName,
        string $inlineBash,
        ?int $timeoutSeconds,
        ?string $flashSuccess,
        bool $dispatchInventorySyncWhenFinished = true,
    ): void {
        $this->systemdRemoteTaskId = null;
        $this->systemdQueueInventoryAfterRemoteTask = $dispatchInventorySyncWhenFinished;

        $id = (string) Str::uuid();
        $ttl = (int) config('server_manage.remote_task_cache_ttl_seconds', 900);

        Cache::put(ServerManageRemoteSshJob::cacheKey($id), [
            'status' => 'queued',
            'output' => '',
            'error' => null,
            'flash_success' => null,
            'queued_at' => time(),
        ], now()->addSeconds(max(120, $ttl)));

        if (config('server_manage.supersede_duplicate_remote_tasks', true)) {
            Cache::put(
                ServerManageRemoteSshJob::activeRequestCacheKey($server->id, $taskName),
                $id,
                now()->addSeconds(max(120, $ttl)),
            );
        }

        ServerManageRemoteSshJob::dispatch(
            $server->id,
            $id,
            $taskName,
            $inlineBash,
            $timeoutSeconds ?? (int) config('task-runner.default_timeout', 60),
            $flashSuccess,
            null,
            \App\Events\Servers\ServerSystemdActionCompletedBroadcast::class,
        );

        $this->systemdRemoteTaskId = $id;
        $this->remote_error = null;
        $this->toastSuccess(__('SSH task queued. The list will refresh automatically while you stay on this page.'));

        // Tell the front-end Echo binder which task id the operator is currently watching, so
        // the .server.systemd.action.completed broadcast filter accepts only the active run.
        $this->js('window.__dplySystemdActionActiveId = '.json_encode($id).';');
    }

    protected function normalizeUnitStatic(string $name): string
    {
        return app(ServerSystemdServicesCatalog::class)->normalizeUnit($name);
    }

    /**
     * @param  callable(string, string):void  $onOutput
     */
    protected function runManageInlineBash(
        Server $server,
        string $taskName,
        string $inlineBash,
        callable $onOutput,
        ?int $timeoutSeconds,
    ): ProcessOutput {
        return app(ServerManageSshExecutor::class)->runInlineBash(
            $server,
            $taskName,
            $inlineBash,
            $timeoutSeconds,
            $onOutput,
        );
    }

    protected function shouldQueueManageRemoteTasks(): bool
    {
        return (bool) config('server_manage.queue_remote_tasks', true);
    }

    protected function manageSshConnectionLabel(Server $server): string
    {
        $host = (string) $server->ip_address;
        $deploy = trim((string) $server->ssh_user) ?: 'root';
        if (! (bool) config('server_manage.use_root_ssh', true)) {
            return $deploy.'@'.$host;
        }

        if ($deploy === 'root') {
            return 'root@'.$host;
        }

        return 'root@'.$host.' ('.__('falls back to').' '.$deploy.'@'.$host.')';
    }
}
