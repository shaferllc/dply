<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

/**
 * @phpstan-type ParsedSuggestion array{
 *     title: string,
 *     summary: string,
 *     confidence: string,
 *     doc_slug: string|null,
 *     actions: list<array{label: string, url: string}>
 * }
 */
final class LlmSynthesisResult
{
    /**
     * @param  array<string, mixed> $suggestions
     * @param  array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $narrative,
        public readonly array $suggestions,
        public readonly ?int $promptTokens = null,
        public readonly ?int $completionTokens = null,
        public readonly ?int $latencyMs = null,
        public readonly ?string $rawContent = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array{narrative: string, suggestions: list<ParsedSuggestion>, prompt_tokens: int|null, completion_tokens: int|null, latency_ms: int|null}
     */
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'narrative' => $this->narrative,
            'suggestions' => $this->suggestions,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'latency_ms' => $this->latencyMs,
            'metadata' => $this->metadata,
        ];
    }
}
