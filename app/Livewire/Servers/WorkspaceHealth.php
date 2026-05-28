<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Services\Servers\ServerHealthCockpit;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * VM server health & capacity cockpit — rolls up metrics, release pressure,
 * deploy failures, certificate expiry, and daemon drift in one view.
 */
#[Layout('layouts.app')]
class WorkspaceHealth extends Component
{
    use InteractsWithServerWorkspace;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.health';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function render(ServerHealthCockpit $cockpit): View
    {
        $this->server->refresh();

        $report = $cockpit->forServer($this->server);

        return view('livewire.servers.workspace-health', [
            'report' => $report,
            'pollSeconds' => (int) config('server_health.ui.poll_seconds', 60),
        ]);
    }
}
