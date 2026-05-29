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
 * Legacy alias route for {@see servers.security-digest} — redirects to the
 * canonical security digest URL when the coming-soon preview is active. The
 * teaser itself lives on the canonical route via {@see WorkspaceSecurityDigest}.
 */
#[Layout('layouts.app')]
class WorkspaceSecurityDigestPreview extends Component
{
    public function mount(Server $server): void
    {
        abort_if(Feature::active('workspace.security_digest'), 404);

        if (workspace_security_digest_preview_active()) {
            $this->redirectRoute('servers.security-digest', $server, navigate: true);

            return;
        }

        abort(404);
    }

    public function render(): View|IlluminateView
    {
        abort(404);
    }
}
