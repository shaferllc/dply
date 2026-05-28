<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Services\Servers\ServerCostCard;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceCostCard extends Component
{
    use InteractsWithServerWorkspace;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.server_cost';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        abort_unless($server->isVmHost() && ! $server->isManagedProductHost(), 404);
    }

    public function render(ServerCostCard $costCard): View
    {
        $this->server->refresh();

        return view('livewire.servers.workspace-cost-card', [
            'report' => $costCard->forServer($this->server),
        ]);
    }
}
