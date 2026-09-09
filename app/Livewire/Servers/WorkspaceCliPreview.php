<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Coming-soon placeholder for the server CLI reference when
 * {@see workspace.cli_preview} is on and {@see workspace.cli} is off.
 *
 * Deliberately NOT #[Lazy]. The gating below is the whole point of this
 * component, and under lazy rendering mount() runs on the hydrate POST rather
 * than on the document request — so the GET returned 200 and the page sat on
 * its skeleton forever while the 404 landed on the POST. Static teaser markup
 * gains nothing from a deferred render anyway.
 */
#[Layout('layouts.app')]
class WorkspaceCliPreview extends Component
{
    use InteractsWithServerWorkspace;

    // No return type: Livewire swaps the redirector for its own, so this hands
    // back either its Redirector or null.
    public function mount(Server $server): mixed
    {
        // The real CLI page shipped — send old teaser links there rather than
        // dead-ending bookmarks on a 404.
        if (workspace_cli_active()) {
            return redirect()->route('servers.cli', ['server' => $server]);
        }

        abort_unless(workspace_cli_preview_active(), 404);

        $this->bootWorkspace($server);

        return null;
    }

    public function render(): View
    {
        return view('livewire.servers.workspace-cli-preview');
    }
}
