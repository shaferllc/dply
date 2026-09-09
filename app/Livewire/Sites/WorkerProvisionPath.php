<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Servers\Concerns\BuildsProvisionDiagnostics;
use App\Livewire\Servers\Concerns\BuildsProvisionStepView;
use App\Livewire\Servers\Concerns\InspectsProvisionState;
use App\Livewire\Servers\ProvisionJourney;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Compact provision path for the site Worker servers modal — same step
 * engine as {@see ProvisionJourney}, without the
 * full-page workspace chrome or a redirect when the box is ready.
 */
class WorkerProvisionPath extends Component
{
    use BuildsProvisionDiagnostics;
    use BuildsProvisionStepView;
    use InspectsProvisionState;

    public Server $server;

    public ?string $originSiteId = null;

    public function mount(Server $server, ?string $originSiteId = null): void
    {
        $this->authorize('view', $server);
        $this->server = $server;
        $this->originSiteId = $originSiteId;
    }

    public function render(): View
    {
        $fresh = Server::query()->find($this->server->getKey());
        if ($fresh instanceof Server) {
            $this->server = $fresh;
        }

        $task = $this->provisionTask();
        $steps = $this->steps($task);
        $activeStep = collect($steps)->firstWhere('state', 'active');
        $failedStep = collect($steps)->firstWhere('state', 'failed');

        return view('livewire.sites.partials.worker-provision-path', [
            'task' => $task,
            'steps' => $steps,
            'activeStep' => $activeStep,
            'failedStep' => $failedStep,
            'completedCount' => collect($steps)->where('state', 'completed')->count(),
            'totalCount' => count($steps),
            'shouldPoll' => $this->shouldPoll(),
            'liveTaskOutput' => $this->liveTaskOutput($task),
            'liveTaskOutputLineCount' => $this->liveTaskOutputLineCount($task),
            'deploy' => $this->originDeploy(),
        ]);
    }

    private function originDeploy(): ?SiteDeployment
    {
        if (! filled($this->originSiteId)) {
            return null;
        }

        $origin = Site::query()->find($this->originSiteId);
        if (! $origin instanceof Site) {
            return null;
        }

        $replica = $origin->fleetReplicaSites()
            ->where('server_id', $this->server->id)
            ->first();
        if (! $replica instanceof Site) {
            return null;
        }

        return $replica->deployments()->latest('created_at')->first();
    }
}
