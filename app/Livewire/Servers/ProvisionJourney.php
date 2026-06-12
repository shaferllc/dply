<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Enums\ServerProvider;
use App\Jobs\ProvisionAwsEc2ServerJob;
use App\Jobs\ProvisionAzureServerJob;
use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Jobs\ProvisionHetznerServerJob;
use App\Jobs\ProvisionLinodeServerJob;
use App\Jobs\ProvisionOracleServerJob;
use App\Jobs\ProvisionOvhServerJob;
use App\Jobs\ProvisionUpCloudServerJob;
use App\Jobs\ProvisionVultrServerJob;
use App\Jobs\RunSetupScriptJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Servers\Concerns\BuildsProvisionDiagnostics;
use App\Livewire\Servers\Concerns\BuildsProvisionStepView;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InspectsProvisionState;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesProvisionActions;
use App\Models\Server;
use App\Models\ServerCreateDraft;
use App\Models\ServerProvisionArtifact;
use App\Models\ServerProvisionRun;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\Services\TaskRunnerService;
use App\Services\Servers\ProvisionStepEtaService;
use App\Services\Servers\ServerJourneyInfrastructureAlerts;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Support\Servers\ClassifyProvisionFailure;
use App\Support\Servers\FakeCloudProvision;
use App\Support\Servers\InstalledStack;
use App\Support\Servers\ProvisionPipelineLog;
use App\Support\Servers\ProvisionStepDurations;
use App\Support\Servers\ProvisionStepSnapshots;
use App\Support\Servers\ProvisionVerificationSummary;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Livewire;

#[Layout('layouts.app')]
class ProvisionJourney extends Component
{
    use BuildsProvisionDiagnostics;
    use BuildsProvisionStepView;
    use HandlesServerRemovalFlow;
    use InspectsProvisionState;
    use InteractsWithServerWorkspace;
    use ManagesProvisionActions;

    public bool $showCancelProvisionModal = false;

    public bool $showResumeInstallModal = false;

    /** @var string SHA-256 of last logged journey snapshot (avoids log spam on wire:poll). */
    public string $journeyViewLogSignature = '';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function render(): View
    {
        // Server may have been deleted out from under us (e.g. user just confirmed
        // the remove-server modal). Bail to the index instead of letting refresh()
        // throw ModelNotFoundException, which surfaces in the UI as a 404 inside
        // any lingering modal/poll response.
        $fresh = Server::query()->find($this->server->getKey());
        if ($fresh === null) {
            $this->showRemoveServerModal = false;
            $this->showCancelProvisionModal = false;

            if (Livewire::isLivewireRequest()) {
                $this->dispatch('provision-journey-complete', url: route('servers.index'));
            } else {
                $this->redirectRoute('servers.index', navigate: true);
            }

            return view('livewire.servers.provision-journey-removed');
        }
        $this->server = $fresh;

        if ($this->shouldRedirectToServerOverview()) {
            if (Livewire::isLivewireRequest()) {
                $this->dispatch('provision-journey-complete', url: route('servers.overview', $this->server));
            } else {
                $this->redirectRoute('servers.overview', $this->server, navigate: true);
            }
        }

        $task = $this->provisionTask();
        $run = $this->provisionRun();
        $artifacts = $run?->artifacts()->get() ?? collect();
        $steps = $this->steps($task);
        $completedCount = collect($steps)->where('state', 'completed')->count();
        $shouldPoll = $this->shouldPoll();
        $verificationChecks = $this->verificationChecks($artifacts);
        $failureClassification = $this->failureClassification($task, $steps, $run, $verificationChecks);
        $repairGuidance = $this->repairGuidance($task, $steps, $run, $verificationChecks, $failureClassification);
        $stackSummary = $this->stackSummary($artifacts);
        $stallState = $this->stallState($task, $steps);

        $stepStatesSig = collect($steps)
            ->map(fn (array $s): string => ($s['key'] ?? '').':'.$s['state'])
            ->implode('|');
        $activeRow = collect($steps)->firstWhere('state', 'active');
        $failedRow = collect($steps)->firstWhere('state', 'failed');
        $sigPayload = [
            'server_status' => $this->server->status,
            'setup_status' => $this->server->setup_status,
            'task_id' => $task?->id,
            'task_status' => $task?->status?->value,
            'run_id' => $run?->id,
            'run_status' => $run?->status,
            'step_signature' => $stepStatesSig,
            'active_key' => $activeRow['key'] ?? null,
            'failed_key' => $failedRow['key'] ?? null,
            'should_poll' => $shouldPoll,
        ];
        $sig = hash('sha256', json_encode($sigPayload, JSON_THROW_ON_ERROR));
        if ($this->journeyViewLogSignature !== $sig) {
            $this->journeyViewLogSignature = $sig;
            ProvisionPipelineLog::info('server.provision.journey.view_state', $this->server, [
                'phase' => 'ui',
                'active_step' => $activeRow['label'] ?? null,
                'failed_step' => $failedRow['label'] ?? null,
                'completed_steps' => $completedCount,
                'total_steps' => count($steps),
                'task_status' => $task?->status?->value,
                'run_status' => $run?->status,
                'should_poll' => $shouldPoll,
            ]);
        }

        return view('livewire.servers.provision-journey', [
            'task' => $task,
            'run' => $run,
            'infrastructureAlerts' => app(ServerJourneyInfrastructureAlerts::class)->forServer($this->server),
            'localDevShellHints' => $this->localDevShellHints(),
            // True when this server row was created during a prior
            // fake-cloud session but DPLY_FAKE_CLOUD_PROVISION is now
            // off. The view surfaces a clear "orphan — delete and
            // recreate" banner instead of misleading the operator with
            // "100% complete" stats from cached fake-cloud step state.
            'isOrphanedFakeServer' => FakeCloudProvision::isOrphanedFakeServer($this->server),
            'artifacts' => $artifacts,
            'steps' => $steps,
            'completedCount' => $completedCount,
            'totalCount' => count($steps),
            'activeStep' => collect($steps)->firstWhere('state', 'active'),
            'pendingSteps' => collect($steps)->where('state', 'pending')->values(),
            'completedSteps' => collect($steps)->where('state', 'completed')->values(),
            'failedStep' => collect($steps)->firstWhere('state', 'failed'),
            'verificationChecks' => $verificationChecks,
            'failureClassification' => $failureClassification,
            'repairGuidance' => $repairGuidance,
            // Reconciled snapshot of what physically landed (vs the
            // wizard's request). View uses this to render the
            // "Requested vs Installed" divergence banner when applicable.
            'installedStack' => InstalledStack::fromMeta($this->server),
            'installedStackDiverges' => InstalledStack::fromMeta($this->server)->divergesFromRequest($this->server),
            'requestedDatabase' => $this->server->meta['database'] ?? null,
            'stackSummary' => $stackSummary,
            'stackTiles' => $stackSummary ? [
                ['label' => __('Role'),        'value' => $stackSummary['role'],         'icon' => 'heroicon-o-rectangle-stack'],
                ['label' => __('Web server'),  'value' => $stackSummary['webserver'],    'icon' => 'heroicon-o-globe-alt'],
                ['label' => __('PHP'),         'value' => $stackSummary['php_version'],  'icon' => 'heroicon-o-code-bracket'],
                ['label' => __('Database'),    'value' => $stackSummary['database'],     'icon' => 'heroicon-o-circle-stack'],
                ['label' => __('Cache'),       'value' => $stackSummary['cache_service'], 'icon' => 'heroicon-o-bolt'],
                ['label' => __('Deploy user'), 'value' => $stackSummary['deploy_user'],  'icon' => 'heroicon-o-user-circle'],
            ] : [],
            'stallState' => $stallState,
            'shouldPoll' => $shouldPoll,
            'canCancelProvision' => $this->canCancelProvision($task),
            'canRetryCloudProvision' => $this->canRetryCloudProvision($this->server),
            'cloudProvisionError' => is_array($this->server->meta['provision_error'] ?? null)
                ? $this->server->meta['provision_error']
                : null,
            'liveTaskOutput' => $this->liveTaskOutput($task),
            'liveTaskOutputLineCount' => $this->liveTaskOutputLineCount($task),
            'taskUpdatedAt' => $task?->updated_at,
            'rollbackSummary' => $this->rollbackSummary($task),
            'failureReason' => $this->failureReason($task, collect($steps)->firstWhere('state', 'failed')),
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server->fresh())
                : null,
        ]);
    }


    /**
     * Map of label_hash → recorded duration_seconds for every step that
     * has emitted an end marker so far in this task's output. Cached
     * per-render via a property to avoid re-parsing on every step row.
     *
     * @return array<string, int>
     */
    private array $stepEndDurationsCache = [];


}
