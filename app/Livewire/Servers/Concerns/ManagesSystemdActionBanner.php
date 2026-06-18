<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;



/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSystemdActionBanner
{


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
        $this->systemdRowBusyAction = null;
        $this->systemdPendingActionUnit = null;
        $this->systemdBulkBusy = false;
        $this->clearSystemdInventoryActiveRow();
    }
}
