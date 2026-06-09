<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Legacy bookmark route — cost card lives on Settings → Cost & compliance.
 */
#[Layout('layouts.app')]
class WorkspaceCostCard extends Component
{
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.server_cost';

    public function mount(Server $server): void
    {
        abort_unless($server->isVmHost() && ! $server->isManagedProductHost(), 404);

        $this->redirect(
            route('servers.settings', ['server' => $server, 'section' => 'governance']).'#settings-cost-estimate',
            navigate: true,
        );
    }

    public function render(): View
    {
        return view('livewire.servers.workspace-cost-card-redirect');
    }
}
