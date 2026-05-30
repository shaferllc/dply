<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceSharedHostPreview extends Component
{
    use InteractsWithServerWorkspace;

    public function mount(Server $server): void
    {
        abort_if(Feature::active('workspace.shared_host'), 404);

        if (workspace_shared_host_preview_active()) {
            $this->redirectRoute('servers.shared-host', $server, navigate: true);

            return;
        }

        abort(404);
    }

    public function render(): View
    {
        return view('livewire.servers.workspace-shared-host-preview');
    }
}
