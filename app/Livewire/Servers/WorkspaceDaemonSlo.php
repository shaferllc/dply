<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RunsServerSupervisorHealthScan;
use App\Models\Server;
use App\Services\Servers\ServerDaemonSloPanel;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceDaemonSlo extends Component
{
    use InteractsWithServerWorkspace;
    use RequiresFeature;
    use RunsServerSupervisorHealthScan;

    protected string $requiredFeature = 'workspace.daemon_slo';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        abort_unless($server->isVmHost() && $server->hostCapabilities()->supportsSsh(), 404);
    }

    public function render(ServerDaemonSloPanel $panel): View
    {
        $this->server->refresh();

        return view('livewire.servers.workspace-daemon-slo', [
            'report' => $panel->forServer($this->server),
        ]);
    }
}
