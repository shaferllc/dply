<?php

declare(strict_types=1);

namespace App\Services\OpsCopilot;

/**
 * One actionable suggestion derived from deploy context heuristics.
 *
 * @phpstan-type SuggestionArray array{
 *     id: string,
 *     title: string,
 *     summary: string,
 *     confidence: string,
 *     doc_slug: string|null,
 *     matched_pattern: string|null,
 * }
 */
final class OpsCopilotSuggestion
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $confidence = 'medium',
        public readonly ?string $docSlug = null,
        public readonly ?string $matchedPattern = null,
    ) {}

    /**
     * @return SuggestionArray
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'summary' => $this->summary,
            'confidence' => $this->confidence,
            'doc_slug' => $this->docSlug,
            'matched_pattern' => $this->matchedPattern,
        ];
    }
}
