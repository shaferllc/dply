<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Concerns\StreamsRemoteSshLivewire;
use App\Services\Servers\SiteLoadAttributorScript;
use App\Services\SshConnection;
use App\Support\Servers\SharedHostBudgetMonitor;
use App\Support\Servers\SiteLoadAttributor;

trait RunsSharedHostAttributionScan
{
    use StreamsRemoteSshLivewire;

    public function refreshSharedHostAttribution(): void
    {
        $this->authorize('update', $this->server);
        if (! $this->canRunSharedHostAttributionScan()) {
            $this->toastError(__('Deployers cannot run shared host attribution scans over SSH.'));

            return;
        }

        if ($this->server->sites()->count() < 2) {
            $this->toastError(__('Shared Host Radar needs at least two sites on this server.'));

            return;
        }

        if (! $this->server->isReady() || ! $this->server->ssh_private_key || empty($this->server->ip_address)) {
            $this->toastError(__('Server must be ready with SSH before scanning site load.'));

            return;
        }

        $sites = app(SiteLoadAttributor::class)->siteScanPayload($this->server);
        if ($sites === []) {
            $this->toastError(__('No sites found to attribute.'));

            return;
        }

        $script = app(SiteLoadAttributorScript::class)->build($sites);
        $wrapped = '/bin/sh -c '.escapeshellarg($script);
        $timeout = max(60, (int) config('server_settings.inventory_ssh_timeout_basic', 120));
        $deploy = trim((string) $this->server->ssh_user) ?: 'root';
        $wantRoot = (bool) config('server_settings.inventory_use_root_ssh', true);
        $fallback = (bool) config('server_settings.inventory_fallback_to_deploy_user_ssh', true);
        $candidates = $wantRoot && $deploy !== 'root' ? array_filter(['root', $fallback ? $deploy : null]) : [$deploy];
        $candidates = array_values(array_filter($candidates));

        $this->resetRemoteSshStreamTargets();
        $lastError = null;
        $out = null;

        foreach ($candidates as $loginUser) {
            try {
                $ssh = new SshConnection($this->server, $loginUser);
                $out = trim($ssh->execWithCallback(
                    $wrapped,
                    fn (string $chunk): mixed => $this->remoteSshStreamAppendStdout($chunk),
                    $timeout,
                ));
                $ssh->disconnect();
                break;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        if ($out === null) {
            $this->toastError($lastError !== null ? $lastError->getMessage() : __('SSH connection failed for site load attribution.'));

            return;
        }

        try {
            $snapshot = app(SiteLoadAttributorScript::class)->parse($out);
            $meta = app(SiteLoadAttributorScript::class)->mergeIntoMeta($snapshot, $this->server->meta ?? []);
            $this->server->update(['meta' => $meta]);
            $this->server->refresh();
            app(SharedHostBudgetMonitor::class)->evaluate($this->server->fresh());
            $this->toastSuccess(__('Site load attribution scan completed.'));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    protected function canRunSharedHostAttributionScan(): bool
    {
        return ! (bool) auth()->user()?->currentOrganization()?->userIsDeployer(auth()->user());
    }
}
