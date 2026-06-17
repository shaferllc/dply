<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiAdvisorRun;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Ai\AiAdvisorRunRecorder;
use App\Services\Ai\LlmSynthesizer;
use App\Services\OpsCopilot\OpsCopilotLlmAdvisor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunOpsCopilotLlmAnalysisJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(
        public string $runId,
        public string $organizationId,
        public string $siteId,
        public ?string $userId = null,
    ) {}

    public function handle(
        AiAdvisorRunRecorder $recorder,
        OpsCopilotLlmAdvisor $advisor,
        LlmSynthesizer $synthesizer,
    ): void {
        $run = AiAdvisorRun::query()->find($this->runId);
        if ($run === null || ! $run->isPending()) {
            return;
        }

        $organization = Organization::query()->find($this->organizationId);
        $site = Site::find($this->siteId);
        if ($organization === null || $site === null) {
            $recorder->fail($run, 'Organization or site not found.');

            return;
        }

        if (! $advisor->canRun($organization)) {
            $recorder->fail($run, 'LLM synthesis is not enabled for this organization.');

            return;
        }

        $context = $advisor->buildLlmPayload($organization, $site);
        if ($context === null) {
            $recorder->fail($run, 'No deploy failure context available.');

            return;
        }

        try {
            $result = $synthesizer->synthesizeOpsCopilot($context);
            $recorder->complete($run, $result);
        } catch (\Throwable $exception) {
            Log::warning('Ops Copilot LLM analysis failed', [
                'run_id' => $run->id,
                'site_id' => $site->id,
                'message' => $exception->getMessage(),
            ]);
            $recorder->fail($run, $exception->getMessage());
        }
    }

    public static function dispatchForSite(
        Organization $organization,
        Site $site,
        ?User $user,
        AiAdvisorRunRecorder $recorder,
        OpsCopilotLlmAdvisor $advisor,
    ): AiAdvisorRun {
        $context = $advisor->buildLlmPayload($organization, $site) ?? [];

        $run = $recorder->start(
            organization: $organization,
            feature: AiAdvisorRun::FEATURE_OPS_COPILOT,
            subject: $site,
            user: $user,
            requestContext: $context,
        );

        self::dispatch($run->id, $organization->id, $site->id, $user?->id);

        return $run;
    }
}
