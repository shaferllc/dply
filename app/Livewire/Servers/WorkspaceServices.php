<?php

namespace App\Livewire\Servers;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesServerSystemdServices;
use App\Models\Server;
use App\Services\Notifications\AssignableNotificationChannels;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceServices extends Component
{
    use ConfirmsActionWithModal;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesServerSystemdServices;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->hydrateSystemdInventoryFromDatabase();
        $this->hydrateSystemdServiceActivityFromDatabase();
    }

    public function render(): View
    {
        $this->server->refresh();

        $user = auth()->user();
        $bulkNotifyChannelOptions = $user !== null
            ? AssignableNotificationChannels::forUser($user, $user->currentOrganization())
            : collect();

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
            'bulkNotifyChannelOptions' => $bulkNotifyChannelOptions,
        ]);
    }
}
