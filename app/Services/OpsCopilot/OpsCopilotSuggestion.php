<?php

declare(strict_types=1);

namespace App\Services\OpsCopilot;

/**
 * One actionable suggestion derived from deploy context heuristics.
 *
 * @phpstan-type SuggestionAction array{label: string, url: string}
 * @phpstan-type SuggestionArray array{
 *     id: string,
 *     title: string,
 *     summary: string,
 *     confidence: string,
 *     doc_slug: string|null,
 *     matched_pattern: string|null,
 *     source: string,
 *     actions: list<SuggestionAction>,
 * }
 */
final class OpsCopilotSuggestion
{
    /**
     * @param  array<string, mixed> $actions
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $confidence = 'medium',
        public readonly ?string $docSlug = null,
        public readonly ?string $matchedPattern = null,
        public readonly string $source = 'heuristic',
        public readonly array $actions = [],
    ) {}

    /**
     * @return SuggestionArray
     */
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'summary' => $this->summary,
            'confidence' => $this->confidence,
            'doc_slug' => $this->docSlug,
            'matched_pattern' => $this->matchedPattern,
            'source' => $this->source,
            'actions' => $this->actions,
        ];
    }

    /**
     * @param  array{title?: string, summary?: string, confidence?: string, doc_slug?: string|null, actions?: list<SuggestionAction>}  $row
     */
    public static function fromLlm(int $index, array $row): self
    {
        $confidence = strtolower((string) ($row['confidence'] ?? 'medium'));
        if (! in_array($confidence, ['high', 'medium', 'low'], true)) {
            $confidence = 'medium';
        }

        $actions = [];
        foreach ($row['actions'] ?? [] as $action) {
            if (! is_array($action)) {
                continue;
            }
            $label = trim((string) ($action['label'] ?? ''));
            $url = trim((string) ($action['url'] ?? ''));
            if ($label === '' || $url === '') {
                continue;
            }
            $actions[] = ['label' => $label, 'url' => $url];
        }

        return new self(
            id: 'llm_'.$index,
            title: (string) ($row['title'] ?? 'Suggestion'),
            summary: (string) ($row['summary'] ?? ''),
            confidence: $confidence,
            docSlug: is_string($row['doc_slug'] ?? null) ? $row['doc_slug'] : null,
            matchedPattern: null,
            source: 'llm',
            actions: $actions,
        );
    }
}
