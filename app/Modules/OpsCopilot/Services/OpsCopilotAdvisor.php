<?php

declare(strict_types=1);

namespace App\Modules\OpsCopilot\Services;

/**
 * Rule-based deploy failure advisor — scans log excerpts for known
 * patterns and returns ranked suggestions. Optional LLM synthesis
 * is a follow-up when config/dply_ops_copilot.php llm.enabled is true.
 */
final class OpsCopilotAdvisor
{
    /**
     * @return list<OpsCopilotSuggestion>
     */
    public function suggest(string $haystack): array
    {
        $haystack = trim($haystack);
        if ($haystack === '') {
            return [];
        }

        $suggestions = [];
        $seenTitles = [];

        foreach (config('dply_ops_copilot.heuristics', []) as $index => $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $pattern = $rule['pattern'] ?? null;
            $title = $rule['title'] ?? null;
            $summary = $rule['summary'] ?? null;

            if (! is_string($pattern) || $pattern === ''
                || ! is_string($title) || $title === ''
                || ! is_string($summary) || $summary === '') {
                continue;
            }

            if (isset($seenTitles[$title])) {
                continue;
            }

            if (@preg_match($pattern, $haystack) !== 1) {
                continue;
            }

            $seenTitles[$title] = true;
            $suggestions[] = new OpsCopilotSuggestion(
                id: 'heuristic_'.$index,
                title: $title,
                summary: $summary,
                confidence: 'high',
                docSlug: is_string($rule['doc_slug'] ?? null) ? $rule['doc_slug'] : null,
                matchedPattern: $pattern,
            );
        }

        if ($suggestions === [] && $haystack !== '') {
            $suggestions[] = new OpsCopilotSuggestion(
                id: 'generic_review_log',
                title: 'Review the deploy log excerpt',
                summary: 'No known pattern matched yet. Read from the bottom of the log upward for the first error line — that usually points to a missing env var, wrong build command, or dependency version skew.',
                confidence: 'low',
            );
        }

        return $suggestions;
    }
}
