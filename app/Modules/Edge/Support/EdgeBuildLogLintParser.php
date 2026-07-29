<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

/**
 * Pulls dply.yaml lint lines out of a persisted build log so the
 * dashboard can surface config errors without reading the full output.
 */
final class EdgeBuildLogLintParser
{
    /**
     * @return array{
     *     lint_failed: bool,
     *     errors: list<string>,
     *     warnings: list<string>
     * }
     */
    public static function parse(?string $buildLog, ?string $failureReason = null): array
    {
        $errors = [];
        $warnings = [];
        $lintFailed = false;

        if (is_string($failureReason) && $failureReason !== '') {
            if (str_contains($failureReason, 'dply config lint failed')) {
                $lintFailed = true;
                $errors[] = $failureReason;
            }
        }

        if (! is_string($buildLog) || $buildLog === '') {
            return self::result($lintFailed, $errors, $warnings);
        }

        foreach (preg_split('/\r\n|\r|\n/', $buildLog) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^\[dply\.yaml\] ERROR: (.+)$/u', $line, $matches) === 1) {
                $lintFailed = true;
                $errors[] = $matches[1];

                continue;
            }

            if (preg_match('/^\[dply\.yaml\] (.+)$/u', $line, $matches) === 1) {
                $warnings[] = $matches[1];

                continue;
            }

            if (str_contains($line, 'Config lint: FAILED')) {
                $lintFailed = true;
            }
        }

        return self::result($lintFailed, $errors, $warnings);
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @return array{lint_failed: bool, errors: list<string>, warnings: list<string>}
     */
    private static function result(bool $lintFailed, array $errors, array $warnings): array
    {
        return [
            'lint_failed' => $lintFailed,
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }
}
