<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Coming-soon placeholder for the server CLI reference when
 * {@see workspace.cli_preview} is on and {@see workspace.cli} is off.
 */
#[Layout('layouts.app')]
#[Lazy]
class WorkspaceCliPreview extends Component
{
    use InteractsWithServerWorkspace;
    use RendersWorkspacePlaceholder;

    public function mount(Server $server): void
    {
        abort_if(workspace_cli_active(), 404);
        abort_unless(workspace_cli_preview_active(), 404);

        $this->bootWorkspace($server);
    }

    public function render(): View
    {
        return view('livewire.servers.workspace-cli-preview');
    }
}
