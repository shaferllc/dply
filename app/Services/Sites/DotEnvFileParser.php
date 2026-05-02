<?php

declare(strict_types=1);

namespace App\Services\Sites;

/**
 * Minimal .env file parser. Returns [KEY => value] for valid lines,
 * skipping blank lines and `#` comments. Strips matching surrounding
 * single or double quotes from values. Keys must match
 * /^[A-Z_][A-Z0-9_]*$/i — non-conforming lines are reported via
 * $errors so the caller can surface them.
 *
 * Deliberately NOT a full bash parser: no variable interpolation,
 * no `export` prefix expansion, no escape sequence handling beyond
 * trimming the outer quotes. The platform should normalize on a
 * single-format we control end-to-end.
 */
class DotEnvFileParser
{
    /**
     * @return array{variables: array<string, string>, errors: array<int, string>}
     */
    public function parse(string $contents): array
    {
        $variables = [];
        $errors = [];

        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        foreach ($lines as $i => $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Allow `export FOO=bar` for compat with shell-sourced envs.
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                $errors[] = sprintf('line %d: missing "=" — "%s"', $i + 1, $rawLine);

                continue;
            }

            $key = trim(substr($line, 0, $eq));
            $value = substr($line, $eq + 1);
            $value = $this->stripInlineComment($value);
            $value = $this->stripSurroundingQuotes($value);

            if (! preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
                $errors[] = sprintf('line %d: invalid key "%s"', $i + 1, $key);

                continue;
            }

            $variables[$key] = $value;
        }

        return ['variables' => $variables, 'errors' => $errors];
    }

    /**
     * Strip `# comment` from the tail of an unquoted value. We only
     * do this when the value is NOT quoted, since `KEY="abc # in quotes"`
     * is intentional.
     */
    private function stripInlineComment(string $value): string
    {
        $trimmed = ltrim($value);
        if (str_starts_with($trimmed, '"') || str_starts_with($trimmed, "'")) {
            return $value;
        }
        $hash = strpos($value, ' #');
        if ($hash !== false) {
            $value = substr($value, 0, $hash);
        }

        return $value;
    }

    private function stripSurroundingQuotes(string $value): string
    {
        $value = trim($value);
        $len = strlen($value);
        if ($len >= 2) {
            $first = $value[0];
            $last = $value[$len - 1];
            if ($first === '"' && $last === '"') {
                $inner = substr($value, 1, -1);

                // Unescape \" and \\ inside double-quoted values to round-trip
                // with our writer. Single-quoted values are taken literally.
                return str_replace(['\\"', '\\\\'], ['"', '\\'], $inner);
            }
            if ($first === "'" && $last === "'") {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}
