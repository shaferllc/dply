<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesReleaseHygieneLogViewer;
use App\Livewire\Servers\Concerns\RunsServerReleaseHygieneScan;
use App\Models\Server;
use App\Services\Servers\ServerReleaseHygiene;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use Livewire\Attributes\Lazy;

/**
 * Release & disk hygiene — atomic release pressure, log sizes, failed jobs,
 * and a one-click prune saved-command template.
 *
 * When {@see workspace.release_hygiene} is off but
 * {@see workspace.release_hygiene_preview} is on, the canonical /hygiene URL
 * renders the coming-soon teaser in place of the full workspace.
 */
#[Layout('layouts.app')]
#[Lazy]
class WorkspaceReleaseHygiene extends Component
{
    use RendersWorkspacePlaceholder;
    use InteractsWithServerWorkspace;
    use ManagesReleaseHygieneLogViewer;
    use RequiresFeature;
    use RunsServerReleaseHygieneScan;

    protected string $requiredFeature = 'workspace.release_hygiene';

    /** When true, render the coming-soon teaser instead of the full workspace. */
    public bool $comingSoonPreview = false;

    public function mount(Server $server): void
    {
        abort_unless($server->isVmHost() && $server->hostCapabilities()->supportsSsh(), 404);

        if (! Feature::active('workspace.release_hygiene')) {
            if (workspace_release_hygiene_preview_active()) {
                $this->comingSoonPreview = true;
                $this->bootWorkspace($server);

                return;
            }

            abort(404);
        }

        $this->comingSoonPreview = false;
        $this->bootWorkspace($server);
        $this->mountReleaseHygieneLogViewer();
    }

    public function bootedRequiresFeature(): void
    {
        if ($this->comingSoonPreview) {
            return;
        }

        $flag = $this->requiredFeature ?? '';
        if ($flag !== '' && ! Feature::active($flag)) {
            abort(404);
        }
    }

    public function render(ServerReleaseHygiene $hygiene): View
    {
        if ($this->comingSoonPreview) {
            return view('livewire.servers.workspace-release-hygiene-preview');
        }

        $this->server->refresh();

        return view('livewire.servers.workspace-release-hygiene', [
            'report' => $hygiene->forServer($this->server),
            'formatBytes' => fn (int $bytes): string => $hygiene->formatBytes($bytes),
        ]);
    }
}
