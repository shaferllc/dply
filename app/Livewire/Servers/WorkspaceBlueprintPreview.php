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
 * Legacy alias route for {@see servers.blueprint} — redirects to the canonical
 * blueprint URL when the coming-soon preview is active.
 */
#[Layout('layouts.app')]
class WorkspaceBlueprintPreview extends Component
{
    public function mount(Server $server): void
    {
        abort_if(Feature::active('workspace.server_blueprint'), 404);

        if (workspace_server_blueprint_preview_active()) {
            $this->redirectRoute('servers.blueprint', $server, navigate: true);

            return;
        }

        abort(404);
    }

    public function render(): View|IlluminateView
    {
        abort(404);
    }
}
