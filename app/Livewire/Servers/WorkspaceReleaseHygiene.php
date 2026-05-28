<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RunsServerReleaseHygieneScan;
use App\Models\Server;
use App\Services\Servers\ServerReleaseHygiene;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Release & disk hygiene — atomic release pressure, log sizes, failed jobs,
 * and a one-click prune saved-command template.
 */
#[Layout('layouts.app')]
class WorkspaceReleaseHygiene extends Component
{
    use InteractsWithServerWorkspace;
    use RequiresFeature;
    use RunsServerReleaseHygieneScan;

    protected string $requiredFeature = 'workspace.release_hygiene';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);

        abort_unless($server->isVmHost() && $server->hostCapabilities()->supportsSsh(), 404);
    }

    public function render(ServerReleaseHygiene $hygiene): View
    {
        $this->server->refresh();

        return view('livewire.servers.workspace-release-hygiene', [
            'report' => $hygiene->forServer($this->server),
            'formatBytes' => fn (int $bytes): string => $hygiene->formatBytes($bytes),
        ]);
    }
}
