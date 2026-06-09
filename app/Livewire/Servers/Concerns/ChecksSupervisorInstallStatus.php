<?php

namespace App\Livewire\Servers\Concerns;

use App\Models\Server;
use App\Services\Servers\SupervisorProvisioner;

/**
 * Shared supervisor-install awareness for any Livewire page that needs to gate
 * its UI on whether the Supervisor package is present (Daemons and
 * site-scoped variants). Tri-state mirrors the underlying enum:
 *
 *  - null  → not yet checked (host hasn't been probed since provision)
 *  - false → confirmed missing (show install affordance / disable actions)
 *  - true  → confirmed installed (normal UI)
 *
 * Pages render a `wire:init="refreshSupervisorInstallStatus"` whenever they
 * see a null value so the first paint settles to the right state without an
 * explicit click.
 */
trait ChecksSupervisorInstallStatus
{
    /** null = not checked yet (wire:init), true/false from supervisor_package_status / dpkg on server. */
    public ?bool $supervisor_installed = null;

    /**
     * Seed the property from the server row's cached enum. Call from mount() so
     * the first render avoids a "checking…" spinner when we already know.
     */
    protected function initSupervisorInstallStatus(Server $server): void
    {
        $this->supervisor_installed = match ($server->supervisor_package_status) {
            Server::SUPERVISOR_PACKAGE_INSTALLED => true,
            Server::SUPERVISOR_PACKAGE_MISSING => false,
            default => null,
        };
    }

    /**
     * wire:init target — resolves the tri-state. If the server row already has a
     * definitive enum, we use it; otherwise probe via SSH and cache the result.
     */
    public function refreshSupervisorInstallStatus(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('view', $this->server);
        $this->server->refresh();
        if ($this->server->supervisor_package_status !== null) {
            $this->supervisor_installed = $this->server->supervisor_package_status === Server::SUPERVISOR_PACKAGE_INSTALLED;

            return;
        }
        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->supervisor_installed = false;

            return;
        }
        $installed = $provisioner->isSupervisorPackageInstalled($this->server->fresh());
        $this->server->update([
            'supervisor_package_status' => $installed ? Server::SUPERVISOR_PACKAGE_INSTALLED : Server::SUPERVISOR_PACKAGE_MISSING,
        ]);
        $this->supervisor_installed = $installed;
    }
}
