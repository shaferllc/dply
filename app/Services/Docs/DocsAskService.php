<?php

declare(strict_types=1);

namespace App\Services\Docs;

use App\Models\AiAdvisorRun;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiAdvisorRunRecorder;
use App\Services\Ai\AiRateLimiter;
use App\Services\Ai\LlmSynthesizer;
use App\Support\Docs\ContextualDocResolver;

final class DocsAskService
{
    public function __construct(
        private readonly DocChunkExtractor $chunks,
        private readonly LlmSynthesizer $synthesizer,
        private readonly AiAdvisorRunRecorder $recorder,
        private readonly AiRateLimiter $rateLimiter,
        private readonly ContextualDocResolver $resolver,
    ) {}

    public function canRun(?Organization $organization): bool
    {
        return $organization !== null
            && ai_llm_active($organization)
            && (bool) config('dply_ai.features.docs_ask', true)
            && $this->synthesizer->isConfigured();
    }

    public function tooManyAttempts(Organization $organization): bool
    {
        return $this->rateLimiter->tooManyAttempts($organization);
    }

    /**
     * @return array{answer: string, confidence: string, cited_headings: list<string>, run_id: string|null, error: string|null}
     */
    public function ask(
        Organization $organization,
        ?User $user,
        string $slug,
        string $question,
        ?string $routeName = null,
    ): array {
        $question = trim($question);
        if ($question === '') {
            return [
                'answer' => '',
                'confidence' => 'low',
                'cited_headings' => [],
                'run_id' => null,
                'error' => __('Enter a question first.'),
            ];
        }

        if (! $this->canRun($organization)) {
            return [
                'answer' => '',
                'confidence' => 'low',
                'cited_headings' => [],
                'run_id' => null,
                'error' => __('Docs Ask is not enabled for this organization.'),
            ];
        }

        if ($this->tooManyAttempts($organization)) {
            return [
                'answer' => '',
                'confidence' => 'low',
                'cited_headings' => [],
                'run_id' => null,
                'error' => __('AI analysis rate limit reached. Try again later.'),
            ];
        }

        $resolvedSlug = $this->resolver->resolve($slug);
        if ($resolvedSlug === 'docs-index' || $this->resolver->isVirtualOnlySlug($resolvedSlug)) {
            return [
                'answer' => '',
                'confidence' => 'low',
                'cited_headings' => [],
                'run_id' => null,
                'error' => __('Open a specific guide page before asking a question.'),
            ];
        }

        try {
            $chunk = $this->chunks->excerptForSlug($resolvedSlug);
        } catch (\Throwable) {
            return [
                'answer' => '',
                'confidence' => 'low',
                'cited_headings' => [],
                'run_id' => null,
                'error' => __('Documentation excerpt not available for this page.'),
            ];
        }

        $run = $this->recorder->start(
            organization: $organization,
            feature: AiAdvisorRun::FEATURE_DOCS_ASK,
            subject: null,
            user: $user,
            requestContext: [
                'slug' => $resolvedSlug,
                'question' => $question,
                'route' => $routeName,
            ],
        );

        try {
            $result = $this->synthesizer->synthesizeDocsAsk(
                docSlug: $resolvedSlug,
                docTitle: $chunk['title'],
                docExcerpt: $chunk['excerpt'],
                routeName: $routeName ?? 'unknown',
                question: $question,
            );

            $this->recorder->complete($run, $result);

            $response = $run->fresh()?->response;
            $metadata = is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
            $cited = is_array($metadata['cited_headings'] ?? null)
                ? array_values(array_filter(array_map('strval', $metadata['cited_headings'])))
                : [];

            return [
                'answer' => $result->narrative,
                'confidence' => is_string($metadata['confidence'] ?? null) ? (string) $metadata['confidence'] : 'medium',
                'cited_headings' => $cited,
                'run_id' => $run->id,
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            $this->recorder->fail($run, $exception->getMessage());

            return [
                'answer' => '',
                'confidence' => 'low',
                'cited_headings' => [],
                'run_id' => $run->id,
                'error' => $exception->getMessage(),
            ];
        }
    }
}
