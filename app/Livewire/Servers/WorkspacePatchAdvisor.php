<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RunsServerInventoryProbe;
use App\Models\Server;
use App\Services\Servers\ServerPatchAdvisor;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * OS patch & reboot advisor — read-only rollup of apt updates, reboot flags,
 * uptime, and unattended-upgrades state from the inventory probe.
 */
#[Layout('layouts.app')]
class WorkspacePatchAdvisor extends Component
{
    use InteractsWithServerWorkspace;
    use RequiresFeature;
    use RunsServerInventoryProbe;

    protected string $requiredFeature = 'workspace.patch_advisor';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);

        abort_unless($server->isVmHost() && $server->hostCapabilities()->supportsSsh(), 404);
    }

    protected function forceExtendedInventoryProbe(): bool
    {
        return true;
    }

    public function render(ServerPatchAdvisor $advisor): View
    {
        $this->server->refresh();

        return view('livewire.servers.workspace-patch-advisor', [
            'report' => $advisor->forServer($this->server),
        ]);
    }
}
