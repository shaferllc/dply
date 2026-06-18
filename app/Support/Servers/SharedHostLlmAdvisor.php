<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\AiAdvisorRun;
use App\Models\Organization;
use App\Models\Server;
use App\Services\Ai\AiAdvisorRunRecorder;
use App\Services\Ai\AiRateLimiter;
use App\Services\Ai\LlmSynthesizer;

final class SharedHostLlmAdvisor
{
    public function __construct(
        private readonly LlmSynthesizer $synthesizer,
        private readonly AiAdvisorRunRecorder $recorder,
        private readonly AiRateLimiter $rateLimiter,
        private readonly SharedHostFairnessAdvisor $fairnessAdvisor,
    ) {}

    public function canRun(Organization $organization): bool
    {
        return ai_llm_active($organization)
            && (bool) config('dply_ai.features.shared_host', true)
            && $this->synthesizer->isConfigured();
    }

    public function latestRun(Server $server): ?AiAdvisorRun
    {
        return $this->recorder->latestForSubject($server, AiAdvisorRun::FEATURE_SHARED_HOST);
    }

    public function narrativeFromRun(?AiAdvisorRun $run): ?string
    {
        if ($run === null || ! $run->isCompleted()) {
            return null;
        }

        $response = $run->response;
        if (! is_array($response)) {
            return null;
        }

        $narrative = trim((string) ($response['narrative'] ?? ''));

        return $narrative !== '' ? $narrative : null;
    }

    public function tooManyAttempts(Organization $organization): bool
    {
        return $this->rateLimiter->tooManyAttempts($organization);
    }

    /**
     * Heuristic briefing for notifications when LLM is unavailable.
     *
     * @param  array<string, mixed> $report
     */
    public function heuristicBriefing(Server $server, array $report): string
    {
        $advisor = $this->fairnessAdvisor->advise($server, $report);
        $lines = [trim($advisor['summary'])];

        foreach (array_slice($advisor['recommendations'], 0, 2) as $recommendation) {
            $lines[] = '• '.trim($recommendation['title']).' — '.trim($recommendation['summary']);
        }

        return trim(implode("\n", array_filter($lines)));
    }

    /**
     * Prefer cached LLM narrative; fall back to heuristic briefing.
     *
     * @param  array<string, mixed> $report
     */
    public function notificationBriefing(Server $server, array $report): string
    {
        $narrative = $this->narrativeFromRun($this->latestRun($server));
        if ($narrative !== null) {
            return $narrative;
        }

        return $this->heuristicBriefing($server, $report);
    }
}
