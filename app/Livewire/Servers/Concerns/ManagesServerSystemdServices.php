<?php

namespace App\Livewire\Servers\Concerns;

use App\Jobs\ServerManageRemoteSshJob;
use App\Jobs\SyncServerSystemdServicesJob;
use App\Models\NotificationChannel;
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
     * When a queued systemd SSH task finishes, whether to dispatch {@see SyncServerSystemdServicesJob} (false for read-only status).
     */
    protected ?bool $systemdQueueInventoryAfterRemoteTask = null;

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

    /**
     * Channel id => which inventory-driven events notify that channel for the open unit.
     *
     * @var array<string, array{stopped: bool, started: bool, restarted: bool, state_changed: bool}>
     */
    public array $systemdStatusModalAlertMatrix = [];

    /**
     * @var list<array{id: string, label: string}>
     */
    public array $systemdStatusModalChannelRows = [];

    /** @var null|'action'|'status_modal' */
    protected ?string $systemdPendingKind = null;

    protected ?string $systemdPendingActionUnit = null;

    public ?string $systemdRowBusyUnit = null;

    public bool $systemdBulkBusy = false;

    public string $systemdFilterSearch = '';

    public string $systemdFilterActive = 'all';

    public string $systemdFilterCustom = 'all';

    public string $systemdFilterNotify = 'all';

    public bool $showSystemdBulkNotifyModal = false;

    public string $systemdBulkNotifyChannelId = '';

    /** @var array<string, bool> */
    public array $systemdBulkNotifyKinds = [
        'stopped' => true,
        'started' => false,
        'restarted' => false,
        'state_changed' => true,
    ];

    public ?string $systemdEntrySnippet = null;

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
     * Status button entrypoint (separate from {@see openSystemdAlertsModalForService} so wire:loading does not cross wires).
     */
    public function openSystemdStatusModalForService(string $unit): void
    {
        $this->openSystemdServiceModal($unit, true);
    }

    public function openSystemdAlertsModalForService(string $unit): void
    {
        $this->openSystemdServiceModal($unit, false);
    }

    public function closeSystemdStatusModal(): void
    {
        $this->showSystemdStatusModal = false;
        $this->systemdStatusModalUnit = '';
        $this->systemdStatusModalUnitNormalized = null;
        $this->systemdStatusModalOutput = '';
        $this->systemdStatusModalAlertMatrix = [];
        $this->systemdStatusModalChannelRows = [];
        $this->systemdStatusModalLoading = false;
        $this->systemdStatusModalError = null;
        $this->systemdEntrySnippet = null;
    }

    /**
     * @param  bool  $fetchStatus  When false, only configure notification routing (no SSH).
     */
    public function openSystemdServiceModal(string $unit, bool $fetchStatus = true): void
    {
        $this->authorize('update', $this->server);
        $this->remote_error = null;
        $this->flash_success = null;

        if ($this->systemdDeployerWorkspaceBlocked()) {
            $this->remote_error = __('Deployers cannot control services on servers.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->remote_error = __('Provisioning and SSH must be ready before running actions.');

            return;
        }

        try {
            $normalized = app(ServerSystemdServicesCatalog::class)->assertSafeUnitNameForStatus($unit);
        } catch (\InvalidArgumentException $e) {
            $this->remote_error = $e->getMessage();

            return;
        }

        $this->showSystemdStatusModal = true;
        $this->systemdStatusModalUnit = $normalized;
        $this->systemdStatusModalUnitNormalized = $normalized;
        $this->systemdStatusModalError = null;
        $this->loadSystemdStatusModalAlertMatrix();

        if (! $fetchStatus) {
            $this->systemdStatusModalLoading = false;
            $this->systemdStatusModalOutput = '';

            return;
        }

        // Inline SSH in this request (same as Refresh). Avoid $this->js() deferred calls — Livewire xjs scope
        // does not bind $wire, so deferred fetch often never ran and the modal stuck on “Fetching…”.
        $this->systemdStatusModalLoading = true;
        $this->systemdStatusModalError = null;
        $this->systemdStatusModalOutput = '';
        $this->fillSystemdModalStatusFromRemoteSsh($normalized);
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
        $script = $this->systemdActionBash($normalized, 'status');

        set_time_limit((int) config('server_services.systemd_action_timeout', 180) + 30);
        $timeout = (int) config('server_services.systemd_action_timeout', 180);

        try {
            $server = $this->server->fresh();
            $out = $this->runManageInlineBash(
                $server,
                'services-systemd:'.$normalized.':status',
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

    public function saveSystemdStatusModalAlertPreferences(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->flash_error = __('Deployers cannot change notification routing.');

            return;
        }

        $unit = $this->systemdStatusModalUnitNormalized;
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
                $row = $this->systemdStatusModalAlertMatrix[$cid] ?? [];
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

        $this->flash_success = __('Alert preferences saved.');
        $this->flash_error = null;
        $this->loadSystemdStatusModalAlertMatrix();
        $this->hydrateSystemdInventoryFromDatabase();
    }

    protected function loadSystemdStatusModalAlertMatrix(): void
    {
        $unit = $this->systemdStatusModalUnitNormalized;
        $this->systemdStatusModalAlertMatrix = [];
        $this->systemdStatusModalChannelRows = [];
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
            $this->systemdStatusModalAlertMatrix[$cid] = $entry;
        }

        $this->systemdStatusModalChannelRows = $channels
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
     */
    protected function queueSystemdInventorySync(bool $silent): void
    {
        $this->authorize('update', $this->server);
        $this->remote_error = null;

        if ($this->currentUserIsDeployer()) {
            $this->remote_error = __('Deployers cannot sync services on servers.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->remote_error = __('Provisioning and SSH must be ready before syncing services.');

            return;
        }

        if (! (bool) config('server_services.systemd_inventory_job_enabled', true)) {
            $this->remote_error = __('Service sync jobs are disabled in configuration.');

            return;
        }

        SyncServerSystemdServicesJob::dispatch($this->server->id);
        if (! $silent) {
            $this->flash_success = __('Service sync queued. This page refreshes from the database every few seconds while you stay here—ensure a queue worker is running.');
        }
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
            $this->remote_error = __('Deployers cannot control services on servers.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->remote_error = __('Provisioning and SSH must be ready before running actions.');

            return;
        }

        $allowedActions = ['start', 'stop', 'restart', 'reload', 'disable', 'enable'];
        if (! in_array($action, $allowedActions, true)) {
            $this->remote_error = __('Unknown action.');

            return;
        }

        try {
            $normalized = app(ServerSystemdServicesCatalog::class)->assertAllowedOnServer($this->server->fresh(), $unit);
        } catch (\InvalidArgumentException $e) {
            $this->remote_error = $e->getMessage();

            return;
        }

        $catalog = app(ServerSystemdServicesCatalog::class);
        if ($catalog->isUnitStatusOnlyForServer($this->server->fresh(), $normalized)) {
            $this->remote_error = __('This unit is status-only for your organization. Inspect it with Status; mutating actions are disabled.');

            return;
        }

        $script = $this->systemdActionBash($normalized, $action);
        $this->systemdPendingKind = 'action';
        $this->systemdRowBusyUnit = $normalized;
        $this->systemdPendingActionUnit = $normalized;

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

                return;
            }

            $this->runManageInlineBash(
                $server,
                'services-systemd:'.$normalized.':'.$action,
                $script,
                static function (string $type, string $buffer): void {},
                $timeout,
            );
            $this->flash_success = $flash;
            $this->remote_error = null;
            if ($syncInventoryAfter && (bool) config('server_services.systemd_inventory_job_enabled', true)) {
                SyncServerSystemdServicesJob::dispatch($this->server->id);
            }
        } catch (\Throwable $e) {
            $this->remote_error = $e->getMessage();
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
            $this->flash_error = __('Select at least one service.');

            return;
        }
        $catalog = app(ServerSystemdServicesCatalog::class);
        $normalized = [];
        foreach ($units as $u) {
            try {
                $normalized[] = $catalog->assertAllowedOnServer($this->server->fresh(), $u);
            } catch (\InvalidArgumentException $e) {
                $this->flash_error = $e->getMessage();

                return;
            }
        }
        $normalized = array_unique($normalized);
        foreach ($normalized as $u) {
            if ($catalog->isUnitStatusOnlyForServer($this->server->fresh(), $u)) {
                $this->flash_error = __('One or more selected units are status-only and cannot be changed from here.');

                return;
            }
        }
        $script = implode("\n", array_map(
            fn (string $u) => $this->systemdActionBash($u, $action)."\n",
            $normalized
        ))."exit 0\n";

        $this->authorize('update', $this->server);
        $this->remote_error = null;
        $this->flash_error = null;

        if ($this->systemdDeployerWorkspaceBlocked()) {
            $this->remote_error = __('Deployers cannot control services on servers.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->remote_error = __('Provisioning and SSH must be ready before running actions.');

            return;
        }

        $this->systemdPendingKind = 'action';
        $this->systemdBulkBusy = true;
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

                return;
            }

            $this->runManageInlineBash(
                $server,
                'services-systemd-bulk:'.$action,
                $script,
                static function (string $type, string $buffer): void {},
                $timeout,
            );
            $this->flash_success = $flash;
            if ((bool) config('server_services.systemd_inventory_job_enabled', true)) {
                SyncServerSystemdServicesJob::dispatch($this->server->id);
            }
        } catch (\Throwable $e) {
            $this->remote_error = $e->getMessage();
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
            $this->flash_error = __('Deployers cannot change custom services.');

            return;
        }

        try {
            $normalized = app(ServerSystemdServicesCatalog::class)->validateAndNormalizeCustomUnit($this->newCustomSystemdUnit);
        } catch (\InvalidArgumentException $e) {
            $this->flash_error = $e->getMessage();

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
            $this->flash_error = __('That unit is already listed.');

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
        $this->flash_success = __('Custom unit saved. A background sync will refresh the list when the worker runs.');
        $this->flash_error = null;
    }

    public function removeCustomSystemdUnit(string $unit): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->flash_error = __('Deployers cannot change custom services.');

            return;
        }

        try {
            $normalized = app(ServerSystemdServicesCatalog::class)->validateAndNormalizeCustomUnit($unit);
        } catch (\InvalidArgumentException $e) {
            $this->flash_error = $e->getMessage();

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
        $this->flash_success = __('Custom unit removed. A background sync will refresh the list when the worker runs.');
        $this->flash_error = null;
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
                ? __('Task still queued. Ensure a queue worker is running (e.g. php artisan queue:work) and that CACHE_DRIVER is shared with the worker (not "array").')
                : __('Task queued…'),
            'running' => __('Running on server…'),
            default => '',
        };

        $err = $payload['error'] ?? null;
        $this->remote_error = is_string($err) && $err !== '' ? $err : null;

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

        if (! in_array($status, ['finished', 'failed'], true)) {
            if ($pendingKind === 'action' && $statusHint !== '') {
                $this->flash_success = $statusHint;
            }

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
            $this->flash_success = is_string($flash) && $flash !== ''
                ? $flash
                : __('Service action finished.');
            $this->remote_error = null;
            if ($shouldSyncInventory && (bool) config('server_services.systemd_inventory_job_enabled', true)) {
                SyncServerSystemdServicesJob::dispatch($this->server->id);
            }
        } elseif ($status === 'failed' && $pendingKind === 'action') {
            $this->flash_success = null;
        } else {
            $this->flash_success = null;
        }

        Cache::forget(ServerManageRemoteSshJob::cacheKey($this->systemdRemoteTaskId));
        $this->systemdRemoteTaskId = null;
        $this->systemdPendingKind = null;
        $this->systemdQueueInventoryAfterRemoteTask = null;
        $this->clearSystemdActionBusyState();
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

        $n = $this->systemdFilterNotify;
        if ($n === 'subscribed') {
            $rows = array_values(array_filter($rows, fn (array $r): bool => ((int) ($r['alert_subscription_count'] ?? 0)) > 0));
        } elseif ($n === 'none') {
            $rows = array_values(array_filter($rows, fn (array $r): bool => ((int) ($r['alert_subscription_count'] ?? 0)) === 0));
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

    public function openSystemdBulkNotifyModal(): void
    {
        $this->authorize('update', $this->server);
        $this->flash_error = null;
        if ($this->currentUserIsDeployer()) {
            $this->flash_error = __('Deployers cannot configure service notifications.');

            return;
        }

        $units = array_values(array_unique($this->systemdSelectedList));
        if ($units === []) {
            $this->flash_error = __('Select at least one service.');

            return;
        }

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        $channels = AssignableNotificationChannels::forUser($user, $user->currentOrganization());
        if ($channels->isEmpty()) {
            $this->flash_error = __('No notification channels are available for your account.');

            return;
        }

        if ($this->systemdBulkNotifyChannelId === '' || ! $channels->pluck('id')->map(fn ($id) => (string) $id)->contains($this->systemdBulkNotifyChannelId)) {
            $this->systemdBulkNotifyChannelId = (string) $channels->first()->id;
        }

        $this->showSystemdBulkNotifyModal = true;
    }

    public function closeSystemdBulkNotifyModal(): void
    {
        $this->showSystemdBulkNotifyModal = false;
    }

    public function saveSystemdBulkNotifySubscriptions(): void
    {
        $this->authorize('update', $this->server);
        $this->flash_error = null;
        if ($this->currentUserIsDeployer()) {
            $this->flash_error = __('Deployers cannot configure service notifications.');

            return;
        }

        $channelId = $this->systemdBulkNotifyChannelId;
        if ($channelId === '') {
            return;
        }

        $user = auth()->user();
        if ($user === null) {
            return;
        }

        $channels = AssignableNotificationChannels::forUser($user, $user->currentOrganization());
        $channel = $channels->first(fn (NotificationChannel $c): bool => (string) $c->id === $channelId);
        if ($channel === null) {
            return;
        }

        if (! Gate::allows('manageNotificationChannels', $channel->owner)) {
            $this->flash_error = __('You cannot manage that notification channel.');

            return;
        }

        $kinds = [];
        foreach (ServerSystemdServiceNotificationKeys::KINDS as $kind) {
            if (! empty($this->systemdBulkNotifyKinds[$kind])) {
                $kinds[] = $kind;
            }
        }
        if ($kinds === []) {
            $this->flash_error = __('Choose at least one event type.');

            return;
        }

        $units = array_values(array_unique($this->systemdSelectedList));
        $catalog = app(ServerSystemdServicesCatalog::class);

        DB::transaction(function () use ($units, $channel, $kinds, $catalog): void {
            foreach ($units as $rawUnit) {
                try {
                    $u = $catalog->assertSafeUnitNameForStatus((string) $rawUnit);
                } catch (\InvalidArgumentException) {
                    continue;
                }
                foreach ($kinds as $kind) {
                    $eventKey = ServerSystemdServiceNotificationKeys::eventKey($u, $kind);
                    NotificationSubscription::query()->firstOrCreate([
                        'notification_channel_id' => $channel->id,
                        'subscribable_type' => Server::class,
                        'subscribable_id' => $this->server->id,
                        'event_key' => $eventKey,
                    ]);
                }
            }
        });

        $this->flash_success = __('Notification subscriptions saved for the selected services.');
        $this->showSystemdBulkNotifyModal = false;
        $this->hydrateSystemdInventoryFromDatabase();
    }

    protected function systemdActionBash(string $normalizedUnit, string $action): string
    {
        $u = escapeshellarg($normalizedUnit);

        return match ($action) {
            'status' => '(systemctl status '.$u.' --no-pager -l 2>&1); exit 0',
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
        );

        $this->systemdRemoteTaskId = $id;
        $this->remote_error = null;
        $this->flash_success = __('SSH task queued. The list will refresh automatically while you stay on this page.');
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
