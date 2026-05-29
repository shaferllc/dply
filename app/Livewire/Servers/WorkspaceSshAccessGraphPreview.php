<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Models\Server;
use Illuminate\Contracts\View\View;
use Illuminate\View\View as IlluminateView;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Legacy alias route for {@see servers.ssh-access} — redirects to the canonical
 * SSH access URL when the coming-soon preview is active. The teaser itself lives
 * on the canonical route via {@see WorkspaceSshAccessGraph}.
 */
#[Layout('layouts.app')]
class WorkspaceSshAccessGraphPreview extends Component
{
    public function mount(Server $server): void
    {
        abort_if(Feature::active('workspace.ssh_access_graph'), 404);

        if (workspace_ssh_access_graph_preview_active()) {
            $this->redirectRoute('servers.ssh-access', $server, navigate: true);

            return;
        }

        abort(404);
    }

    public function render(): View|IlluminateView
    {
        abort(404);
    }
}
