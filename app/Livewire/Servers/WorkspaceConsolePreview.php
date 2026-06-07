<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use Livewire\Attributes\Lazy;

/**
 * Coming-soon placeholder for the browser SSH console when
 * {@see workspace.console_preview} is on and {@see workspace.console} is off.
 */
#[Layout('layouts.app')]
#[Lazy]
class WorkspaceConsolePreview extends Component
{
    use RendersWorkspacePlaceholder;
    use InteractsWithServerWorkspace;

    public Server $server;

    public function mount(Server $server): void
    {
        abort_if(workspace_console_active(), 404);
        abort_unless(workspace_console_preview_active(), 404);

        $this->bootWorkspace($server);
    }

    public function render(): View
    {
        return view('livewire.servers.workspace-console-preview');
    }
}
