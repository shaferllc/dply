<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\Server;
use App\Services\Servers\SupervisorProvisioner;
use Illuminate\Support\Str;

trait RunsServerSupervisorHealthScan
{
    public function refreshSupervisorHealth(): void
    {
        $this->authorize('update', $this->server);
        if (! $this->canRunSupervisorHealthScan()) {
            $this->toastError(__('Deployers cannot refresh supervisor health over SSH.'));

            return;
        }

        if (! $this->server->isReady() || ! $this->server->ssh_private_key || empty($this->server->ip_address)) {
            $this->toastError(__('Server must be ready with SSH before checking supervisor health.'));

            return;
        }

        if ($this->server->supervisor_package_status === Server::SUPERVISOR_PACKAGE_MISSING) {
            $this->toastError(__('Supervisor is not installed on this server.'));

            return;
        }

        try {
            $provisioner = app(SupervisorProvisioner::class);
            $out = $provisioner->fetchSupervisorctlStatus($this->server);
            $analysis = $provisioner->analyzeStatusForManagedPrograms($this->server, $out);
            $drift = false;
            try {
                $drift = $provisioner->hasConfigDrift($this->server);
            } catch (\Throwable) {
            }

            $meta = $this->server->meta ?? [];
            $meta['supervisor_health'] = [
                'checked_at' => now()->toIso8601String(),
                'ok' => $analysis['ok'] && ! $drift,
                'summary' => $analysis['summary'],
                'config_drift' => $drift,
                'detail' => Str::limit($out, 8000),
            ];
            $this->server->update(['meta' => $meta]);
            $this->server->refresh();

            if ($analysis['ok'] && ! $drift) {
                $this->toastSuccess(__('Supervisor health refreshed — all managed programs OK.'));
            } else {
                $this->toastError($analysis['summary']);
            }
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    protected function canRunSupervisorHealthScan(): bool
    {
        return ! (bool) auth()->user()?->currentOrganization()?->userIsDeployer(auth()->user());
    }
}
