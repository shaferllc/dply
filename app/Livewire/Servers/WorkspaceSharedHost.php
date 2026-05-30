<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Jobs\RunSharedHostLlmAnalysisJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RunsSharedHostAttributionScan;
use App\Models\AiAdvisorRun;
use App\Models\Server;
use App\Services\Ai\AiAdvisorRunRecorder;
use App\Support\Servers\SharedHostBudgetSettings;
use App\Support\Servers\SharedHostFairnessAdvisor;
use App\Support\Servers\SharedHostLlmAdvisor;
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
    use DispatchesToastNotifications;
    use InteractsWithServerWorkspace;
    use RequiresFeature;
    use RunsSharedHostAttributionScan;

    protected string $requiredFeature = 'workspace.shared_host';

    public bool $comingSoonPreview = false;

    public string $attributionRange = 'current';

    public bool $budgetAlertsEnabled = true;

    public ?string $llmRunId = null;

    /** @var list<array{slug: string, name: string, cpu_share_pct: float, mem_share_pct: float}> */
    public array $budgetSiteRows = [];

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
        $this->hydrateBudgetForm();
    }

    public function setAttributionRange(string $range): void
    {
        if (! in_array($range, ['current', '24h', '7d'], true)) {
            return;
        }

        $this->attributionRange = $range;
    }

    public function saveSharedHostBudgets(): void
    {
        $this->authorize('update', $this->server);

        app(SharedHostBudgetSettings::class)->update($this->server, [
            'alerts_enabled' => $this->budgetAlertsEnabled,
            'site_rows' => $this->budgetSiteRows,
        ]);

        $this->server->refresh();
        $this->hydrateBudgetForm();
        $this->toastSuccess(__('Shared host budgets saved.'));
    }

    public function generateSharedHostLlmAnalysis(
        SharedHostReport $report,
        SharedHostLlmAdvisor $advisor,
        AiAdvisorRunRecorder $recorder,
    ): void {
        $this->authorize('update', $this->server);

        $org = auth()->user()?->currentOrganization();
        if ($org === null || ! $advisor->canRun($org)) {
            $this->toastError(__('AI summary is not enabled for this organization.'));

            return;
        }

        if ($advisor->tooManyAttempts($org)) {
            $this->toastError(__('AI analysis rate limit reached. Try again later.'));

            return;
        }

        $payload = $report->forServer($this->server, $this->attributionRange);
        $run = RunSharedHostLlmAnalysisJob::dispatchForServer(
            organization: $org,
            server: $this->server,
            user: auth()->user(),
            recorder: $recorder,
            reportPayload: $payload,
        );

        $this->llmRunId = $run->id;
        $this->toastSuccess(__('AI summary queued.'));
    }

    public function refreshSharedHostLlmRun(): void
    {
        if ($this->llmRunId === null) {
            return;
        }

        $run = AiAdvisorRun::query()->find($this->llmRunId);
        if ($run === null || $run->isPending()) {
            return;
        }

        if ($run->status === AiAdvisorRun::STATUS_FAILED) {
            $this->toastError($run->error_message ?? __('AI summary failed.'));
        }
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

    public function render(
        SharedHostReport $report,
        SharedHostFairnessAdvisor $fairnessAdvisor,
        SharedHostLlmAdvisor $llmAdvisor,
    ): View {
        if ($this->comingSoonPreview) {
            return view('livewire.servers.workspace-shared-host-preview');
        }

        $this->server->refresh();
        $reportData = $report->forServer($this->server, $this->attributionRange);
        $org = auth()->user()?->currentOrganization();

        $llmRun = null;
        $llmNarrative = null;
        if ($this->llmRunId !== null) {
            $llmRun = AiAdvisorRun::query()->find($this->llmRunId);
        }
        if ($llmRun === null) {
            $llmRun = $llmAdvisor->latestRun($this->server);
            if ($llmRun !== null) {
                $this->llmRunId = $llmRun->id;
            }
        }
        $llmNarrative = $llmAdvisor->narrativeFromRun($llmRun);

        return view('livewire.servers.workspace-shared-host', [
            'report' => $reportData,
            'advisor' => $fairnessAdvisor->advise($this->server, $reportData),
            'llmRun' => $llmRun,
            'llmNarrative' => $llmNarrative,
            'llmCanRun' => $org !== null && $llmAdvisor->canRun($org),
        ]);
    }

    private function hydrateBudgetForm(): void
    {
        $settings = app(SharedHostBudgetSettings::class)->forServer($this->server);
        $this->budgetAlertsEnabled = (bool) ($settings['alerts_enabled'] ?? true);
        $this->budgetSiteRows = $settings['site_rows'] ?? [];
    }
}
