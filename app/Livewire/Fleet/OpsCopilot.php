<?php

declare(strict_types=1);

namespace App\Livewire\Fleet;

use App\Jobs\RunOpsCopilotLlmAnalysisJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\RequiresFeature;
use App\Models\AiAdvisorRun;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Services\Ai\AiAdvisorRunRecorder;
use App\Services\OpsCopilot\OpsCopilotContextBuilder;
use App\Services\OpsCopilot\OpsCopilotLlmAdvisor;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ops Copilot — cross-engine deploy failure triage. Assembles log excerpts,
 * repo config, intelligence alerts, and heuristic fix suggestions for
 * vibe-coded repos that need an ops layer beyond the AI builder.
 */
class OpsCopilot extends Component
{
    use DispatchesToastNotifications;
    use RequiresFeature;

    protected string $requiredFeature = 'global.ops_copilot';

    #[Url(as: 'site', except: '')]
    public string $siteId = '';

    public ?string $llmRunId = null;

    public function generateLlmAnalysis(
        OpsCopilotLlmAdvisor $advisor,
        AiAdvisorRunRecorder $recorder,
    ): void {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        if ($this->siteId === '') {
            $this->toastError(__('Pick a site with a failed deploy first.'));

            return;
        }

        if (! $advisor->canRun($org)) {
            $this->toastError(__('AI analysis is not enabled for this organization.'));

            return;
        }

        if ($advisor->tooManyAttempts($org)) {
            $this->toastError(__('AI analysis rate limit reached. Try again later.'));

            return;
        }

        $selectedSite = $this->resolveSelectedSite($org);
        if ($selectedSite === null) {
            $this->toastError(__('Site not found.'));

            return;
        }

        $run = RunOpsCopilotLlmAnalysisJob::dispatchForSite(
            organization: $org,
            site: $selectedSite,
            user: auth()->user(),
            recorder: $recorder,
            advisor: $advisor,
        );

        $this->llmRunId = $run->id;
        $this->toastSuccess(__('AI analysis queued — results appear shortly.'));
    }

    public function refreshLlmRun(): void
    {
        if ($this->llmRunId === null) {
            return;
        }

        $run = AiAdvisorRun::query()->find($this->llmRunId);
        if ($run === null || $run->isPending()) {
            return;
        }

        if ($run->status === AiAdvisorRun::STATUS_FAILED) {
            $this->toastError($run->error_message ?? __('AI analysis failed.'));
        }
    }

    public function render(
        OpsCopilotContextBuilder $builder,
        OpsCopilotLlmAdvisor $llmAdvisor,
    ): View {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $candidates = $builder->candidateSites($org);
        $context = null;
        $selectedSite = null;
        $llmRun = null;
        $llmSuggestions = [];
        $llmNarrative = null;

        if ($this->siteId !== '') {
            $selectedSite = $this->resolveSelectedSite($org);

            if ($selectedSite !== null) {
                $context = $builder->build($org, $selectedSite);

                if ($this->llmRunId !== null) {
                    $llmRun = AiAdvisorRun::query()->find($this->llmRunId);
                }

                if ($llmRun === null) {
                    $llmRun = $llmAdvisor->latestRun($selectedSite);
                    if ($llmRun !== null) {
                        $this->llmRunId = $llmRun->id;
                    }
                }

                $llmSuggestions = array_map(
                    static fn ($suggestion): array => $suggestion->toArray(),
                    $llmAdvisor->suggestionsFromRun($llmRun),
                );
                $llmNarrative = $llmAdvisor->narrativeFromRun($llmRun);
            }
        }

        return view('livewire.fleet.ops-copilot', [
            'org' => $org,
            'candidates' => $candidates,
            'selectedSite' => $selectedSite,
            'context' => $context,
            'llmRun' => $llmRun,
            'llmSuggestions' => $llmSuggestions,
            'llmNarrative' => $llmNarrative,
            'llmCanRun' => $llmAdvisor->canRun($org),
        ])->layout('layouts.app');
    }

    private function resolveSelectedSite(Organization $org): ?Site
    {
        $serverIds = Server::query()
            ->where('organization_id', $org->id)
            ->pluck('id');

        return Site::query()
            ->whereIn('server_id', $serverIds)
            ->whereKey($this->siteId)
            ->first();
    }
}
