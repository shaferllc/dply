<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RunsSharedHostAttributionScan;
use App\Models\Server;
use App\Support\Servers\SharedHostReport;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Per-site resource attribution, shared stack map, and contention timeline for multi-site VMs.
 */
#[Layout('layouts.app')]
class WorkspaceSharedHost extends Component
{
    use InteractsWithServerWorkspace;
    use RequiresFeature;
    use RunsSharedHostAttributionScan;

    protected string $requiredFeature = 'workspace.shared_host';

    public bool $comingSoonPreview = false;

    public function mount(Server $server): void
    {
        abort_unless($server->isVmHost() && $server->hostCapabilities()->supportsSsh(), 404);

        if (! Feature::active('workspace.shared_host')) {
            if (workspace_shared_host_preview_active()) {
                $this->comingSoonPreview = true;
                $this->bootWorkspace($server);

                return;
            }

            abort(404);
        }

        $this->comingSoonPreview = false;
        $this->bootWorkspace($server);
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

    public function render(SharedHostReport $report): View
    {
        if ($this->comingSoonPreview) {
            return view('livewire.servers.workspace-shared-host-preview');
        }

        $this->server->refresh();

        return view('livewire.servers.workspace-shared-host', [
            'report' => $report->forServer($this->server),
        ]);
    }
}
