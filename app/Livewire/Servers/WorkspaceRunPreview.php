<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Coming-soon placeholder for the Run surface (saved commands + ad-hoc
 * shell) when {@see workspace.run_preview} is on and {@see workspace.run}
 * is off.
 */
#[Layout('layouts.app')]
#[Lazy]
class WorkspaceRunPreview extends Component
{
    use InteractsWithServerWorkspace;
    use RendersWorkspacePlaceholder;

    public Server $server;

    public function mount(Server $server): void
    {
        abort_if(Feature::active('workspace.run'), 404);
        abort_unless(workspace_run_preview_active(), 404);

        $this->bootWorkspace($server);
    }

    public function render(): View
    {
        return view('livewire.servers.workspace-run-preview');
    }
}
