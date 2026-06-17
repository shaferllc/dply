<?php

namespace App\Services\ConfigRevisions\Diff;

/**
 * Diff renderer for webserver_config snapshots:
 *   {
 *     "mode": "layered" | "full_override",
 *     "before_body": "...",
 *     "main_snippet_body": "...",
 *     "after_body": "...",
 *     "full_override_body": "..."
 *   }
 *
 * Renders a per-field block. Empty/unchanged fields collapse to a
 * single "(unchanged)" line so users can scan changes quickly.
 */
class WebserverConfigDiffRenderer implements ConfigRevisionDiffRenderer
{
    /** @var array<string, string> field key => human label */
    private const FIELDS = [
        'mode' => 'mode',
        'before_body' => 'before layer',
        'main_snippet_body' => 'main snippet',
        'after_body' => 'after layer',
        'full_override_body' => 'full override',
    ];

    /**
     * @param  array<string, mixed> $left
     * @param  array<string, mixed> $right
     */
    public function render(array $left, array $right): string
    {
        $blocks = [];

        foreach (self::FIELDS as $key => $label) {
            $leftValue = self::stringValue($left[$key] ?? null);
            $rightValue = self::stringValue($right[$key] ?? null);

            $blocks[] = $this->renderField($label, $leftValue, $rightValue);
        }

        return implode("\n", $blocks);
    }

    private function renderField(string $label, string $left, string $right): string
    {
        $header = '── '.$label.' '.str_repeat('─', max(2, 40 - mb_strlen($label))).PHP_EOL;

        if ($left === $right) {
            return $header.'(unchanged)'.PHP_EOL;
        }

        // For short scalar values like mode, show full before/after rather than
        // a unified diff (which is overkill for a one-word field).
        if (! str_contains($left, "\n") && ! str_contains($right, "\n") && mb_strlen($left.$right) < 200) {
            return $header
                .'- '.$left.PHP_EOL
                .'+ '.$right.PHP_EOL;
        }

        return $header.PhpFileDiffRenderer::renderUnifiedDiff($left, $right);
    }

    private static function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }
}
