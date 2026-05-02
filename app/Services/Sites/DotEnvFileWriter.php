<?php

declare(strict_types=1);

namespace App\Services\Sites;

/**
 * Render an array of env vars back into .env file content.
 * Always quotes with double-quotes when the value contains
 * whitespace, "#", "=", quotes, or backslashes — matches what our
 * own parser will round-trip cleanly. Wraps backslashes and dquotes
 * in escapes inside quoted values.
 *
 * Output is sorted by key for deterministic diffing across runs.
 */
class DotEnvFileWriter
{
    /**
     * @param  array<string, string>  $variables
     */
    public function render(array $variables): string
    {
        ksort($variables);
        $lines = [];
        foreach ($variables as $key => $value) {
            $lines[] = $key.'='.$this->formatValue((string) $value);
        }

        return implode("\n", $lines).(empty($lines) ? '' : "\n");
    }

    private function formatValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/[\s#=\'"\\\\]/', $value) === 1) {
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

            return '"'.$escaped.'"';
        }

        return $value;
    }
}
