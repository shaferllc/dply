<?php

namespace App\Livewire\Servers;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesServerSystemdServices;
use App\Models\Server;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use App\Livewire\Concerns\RequiresFeature;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceServices extends Component
{
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.services';
    use ConfirmsActionWithModal;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesServerSystemdServices;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->hydrateSystemdInventoryFromDatabase();
        $this->hydrateSystemdServiceActivityFromDatabase();
        $this->hydrateSystemdSyncBannerDismissalFromSession();
    }

    public function render(): View
    {
        $this->server->refresh();

        $deployerSystemdLocked = $this->currentUserIsDeployer()
            && ! (bool) ($this->server->organization?->mergedServicesPreferences()['deployer_systemd_actions_enabled'] ?? false);

        return view('livewire.servers.workspace-services', [
            'opsReady' => $this->serverOpsReady(),
            'isDeployer' => $this->currentUserIsDeployer(),
            'deployerSystemdLocked' => $deployerSystemdLocked,
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
            'filteredSystemdInventory' => $this->systemdFilteredInventoryRows(),
            'systemdSyncMeta' => $this->systemdInventorySyncMeta(),
        ]);
    }
}
