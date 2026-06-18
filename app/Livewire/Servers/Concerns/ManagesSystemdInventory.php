<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Events\Servers\ServerSystemdActionCompletedBroadcast;
use App\Jobs\ServerManageRemoteSshJob;
use App\Jobs\SyncServerSystemdServicesJob;
use App\Models\ServerSystemdServiceAuditEvent;
use App\Models\ServerSystemdServiceState;
use App\Services\Servers\ServerSystemdServicesCatalog;
use App\Support\ServerSystemdServiceNotificationKeys;
use App\Support\Servers\SystemdServiceStandbyReasonResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSystemdInventory
{


    public function toggleSystemdShowSystem(): void
    {
        $this->systemdShowSystem = ! $this->systemdShowSystem;
    }

    /**
     * Whether the inventory table should show the loading overlay on this unit's row.
     */
    public function systemdInventoryRowIsBusy(string $unit): bool
    {
        $normalized = app(ServerSystemdServicesCatalog::class)->normalizeUnit($unit);
        if ($normalized === '') {
            return false;
        }

        return ($this->systemdActiveRowUnit !== null && $this->systemdActiveRowUnit === $normalized)
            || ($this->systemdRowBusyUnit !== null && $this->systemdRowBusyUnit === $normalized);
    }

    /**
     * Livewire wire:loading / wire:target scopes for a single inventory row.
     */
    public function systemdInventoryRowWireTargets(string $unit): string
    {
        $encoded = json_encode($unit, JSON_THROW_ON_ERROR);
        $targets = [
            "openSystemdStatusModalForService({$encoded})",
            "openSystemdLogsModalForService({$encoded})",
            "openSystemdNotifyModalForService({$encoded})",
        ];
        foreach (['start', 'restart', 'stop', 'reload', 'enable', 'disable'] as $kind) {
            $targets[] = "runSystemdServiceAction({$encoded}, '{$kind}')";
        }

        return implode(', ', $targets);
    }

    protected function markSystemdInventoryActiveRow(string $unit, string $action): void
    {
        try {
            $normalized = app(ServerSystemdServicesCatalog::class)
                ->assertAllowedOnServer($this->server->fresh(), $unit);
            $this->systemdActiveRowUnit = $normalized;
            $this->systemdActiveRowAction = $action;
        } catch (\InvalidArgumentException) {
            $this->clearSystemdInventoryActiveRow();
        }
    }

    protected function clearSystemdInventoryActiveRow(): void
    {
        $this->systemdActiveRowUnit = null;
        $this->systemdActiveRowAction = null;
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
        $standbyResolver = app(SystemdServiceStandbyReasonResolver::class);

        $this->systemdInventory = $states->map(function (ServerSystemdServiceState $s) use ($countsBySlug, $catalog, $deployerBlocked, $standbyResolver) {
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
                'standby_reason' => $standbyResolver->reasonForUnit($this->server, $s->unit, $s->active_state),
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
     *                        page already shows the cached inventory and we don't want to surprise
     *                        the operator with a banner they didn't trigger.
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
            ServerSystemdActionCompletedBroadcast::class,
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
     * Defense-in-depth: clear the `pending_action` flag on the unit row(s) the operator just
     * acted on and re-hydrate the in-memory inventory in the same Livewire request. Without
     * this, the row stays stuck on "Restarting…" / "Stopping…" until the post-action
     * SyncServerSystemdServicesJob runs and the wire:poll.5s picks up the fresh state — a
     * window of several seconds, longer if the queue worker is slow.
     */
    /**
     * @param  'start'|'restart'|'stop'|'reload'|'enable'|'disable'  $action
     */
    protected function patchSystemdInventoryPendingAction(string $unit, string $action): void
    {
        $this->systemdInventory = array_map(
            static function (array $row) use ($unit, $action): array {
                if (($row['unit'] ?? '') === $unit) {
                    $row['pending_action'] = $action;
                }

                return $row;
            },
            $this->systemdInventory,
        );
    }

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
}
