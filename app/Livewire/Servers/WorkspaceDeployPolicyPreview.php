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
 * Legacy alias route for {@see servers.deploy-policy} — redirects to the
 * canonical deploy windows URL when the coming-soon preview is active. The
 * teaser itself lives on the canonical route via {@see WorkspaceDeployPolicy}.
 */
#[Layout('layouts.app')]
class WorkspaceDeployPolicyPreview extends Component
{
    public function mount(Server $server): void
    {
        abort_if(Feature::active('workspace.deploy_windows'), 404);

        if (workspace_deploy_windows_preview_active()) {
            $this->redirectRoute('servers.deploy-policy', $server, navigate: true);

            return;
        }

        abort(404);
    }

    public function render(): View|IlluminateView
    {
        abort(404);
    }
}
