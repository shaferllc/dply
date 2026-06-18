<?php

declare(strict_types=1);

namespace App\Modules\OpsCopilot\Services;

use App\Models\AiAdvisorRun;
use App\Models\Organization;
use App\Models\Site;
use App\Services\Ai\AiAdvisorRunRecorder;
use App\Services\Ai\AiRateLimiter;
use App\Services\Ai\LlmSynthesizer;

final class OpsCopilotLlmAdvisor
{
    public function __construct(
        private readonly OpsCopilotContextBuilder $contextBuilder,
        private readonly LlmSynthesizer $synthesizer,
        private readonly AiAdvisorRunRecorder $recorder,
        private readonly AiRateLimiter $rateLimiter,
    ) {}

    public function canRun(Organization $organization): bool
    {
        return ai_llm_active($organization)
            && (bool) config('dply_ai.features.ops_copilot', true)
            && $this->synthesizer->isConfigured();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildLlmPayload(Organization $organization, Site $site): ?array
    {
        $context = $this->contextBuilder->build($organization, $site);
        if ($context === null) {
            return null;
        }

        unset($context['suggestions']);

        return $context;
    }

    public function latestRun(Site $site): ?AiAdvisorRun
    {
        return $this->recorder->latestForSubject($site, AiAdvisorRun::FEATURE_OPS_COPILOT);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<OpsCopilotSuggestion>
     */
    public function suggestionsFromRun(?AiAdvisorRun $run): array
    {
        if ($run === null || ! $run->isCompleted()) {
            return [];
        }

        $response = $run->response;
        if (! is_array($response)) {
            return [];
        }

        $suggestions = [];
        foreach ($response['suggestions'] ?? [] as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $suggestions[] = OpsCopilotSuggestion::fromLlm((int) $index, $row);
        }

        return $suggestions;
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
}
