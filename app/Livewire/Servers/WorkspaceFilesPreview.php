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
 * Legacy alias route for {@see servers.files} — redirects to the canonical
 * files URL when the coming-soon preview is active.
 */
#[Layout('layouts.app')]
class WorkspaceFilesPreview extends Component
{
    public function mount(Server $server): void
    {
        abort_if(Feature::active('workspace.files'), 404);

        if (workspace_files_preview_active()) {
            $this->redirectRoute('servers.files', $server, navigate: true);

            return;
        }

        abort(404);
    }

    public function render(): View|IlluminateView
    {
        abort(404);
    }
}
