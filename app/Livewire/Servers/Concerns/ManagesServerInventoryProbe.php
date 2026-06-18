<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\RefreshServerInventoryJob;
use Illuminate\Support\Facades\Cache;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesServerInventoryProbe
{
    public function pollManageWorkspace(): void
    {
        $this->syncManageRemoteTaskFromCache();
    }

    /**
     * wire:init target — queue a background inventory probe when Manage lands with
     * SSH ready but no probe snapshot yet (common right after provision).
     */
    public function maybeRefreshInventoryProbeOnLoad(): void
    {
        if (! (bool) config('server_manage.inventory_probe_refresh_on_load', true)) {
            return;
        }

        $this->attemptAutoInventoryProbeRefresh();
    }

    /**
     * wire:poll target while provisioning is in flight or probe meta is still empty.
     * Refreshes the server row from the database and re-attempts the auto-refresh
     * dispatch once SSH becomes ready.
     */
    public function pollManageInventoryState(): void
    {
        $this->server->refresh();
        $this->attemptAutoInventoryProbeRefresh();
    }

    protected function attemptAutoInventoryProbeRefresh(): void
    {
        if (! $this->shouldAutoRefreshInventoryProbe()) {
            return;
        }

        $cacheKey = 'server-inventory-probe:auto:'.$this->server->id;
        if (! Cache::add($cacheKey, 1, now()->addMinutes(2))) {
            return;
        }

        RefreshServerInventoryJob::dispatch((string) $this->server->id);
    }

    protected function shouldAutoRefreshInventoryProbe(): bool
    {
        if ($this->currentUserIsDeployer()) {
            return false;
        }

        if (! auth()->user()?->can('update', $this->server)) {
            return false;
        }

        if (! $this->serverOpsReady()) {
            return false;
        }

        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $checkedAt = $meta['inventory_checked_at'] ?? null;

        return ! is_string($checkedAt) || trim($checkedAt) === '';
    }

    /** Manage tabs always want the extended snapshot regardless of the user's inventory depth setting. */
}
