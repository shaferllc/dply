<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\NotificationSubscription;
use App\Models\Server;
use App\Services\Notifications\AssignableNotificationChannels;
use App\Services\Servers\ServerManageSshExecutor;
use App\Services\Servers\ServerSystemdServicesCatalog;
use App\Support\ServerSystemdServiceNotificationKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSystemdModals
{


    public function openSystemdActionConfirm(string $kind, ?string $unit = null): void
    {
        if (! in_array($kind, ['start', 'restart', 'stop', 'reload', 'enable', 'disable', 'bulk-restart', 'bulk-stop', 'remove-custom'], true)) {
            return;
        }
        $unitString = (string) $unit;
        $this->clearSystemdInventoryActiveRow();
        $this->systemdActionConfirmKind = $kind;
        $this->systemdActionConfirmUnit = $unitString;
        $this->showSystemdActionConfirm = true;
    }

    public function closeSystemdActionConfirm(): void
    {
        $this->showSystemdActionConfirm = false;
        $this->systemdActionConfirmKind = '';
        $this->systemdActionConfirmUnit = '';
        $this->clearSystemdInventoryActiveRow();
    }

    public function confirmSystemdAction(): void
    {
        $kind = $this->systemdActionConfirmKind;
        $unit = $this->systemdActionConfirmUnit;
        $this->showSystemdActionConfirm = false;
        $this->systemdActionConfirmKind = '';
        $this->systemdActionConfirmUnit = '';

        match (true) {
            $kind === 'bulk-restart' => $this->bulkSystemdRestart(),
            $kind === 'bulk-stop' => $this->bulkSystemdStop(),
            $kind === 'remove-custom' && $unit !== '' => $this->removeCustomSystemdUnit($unit),
            in_array($kind, ['start', 'restart', 'stop', 'reload', 'enable', 'disable'], true) && $unit !== '' => $this->runSystemdServiceAction($unit, $kind),
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
}
