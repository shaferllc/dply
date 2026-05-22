<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\ServerProvisionStepRun;
use App\Services\Servers\ServerProvisionCommandBuilder;

/**
 * Parses `[dply-step-end] <label>\t<seconds>[\tresumed]` markers out of
 * a provision task's stdout. Companion to {@see ProvisionStepSnapshots}
 * but emitted by {@see ServerProvisionCommandBuilder::withStep()}
 * once a step finishes — `ProvisionStepSnapshots` only sees start
 * markers and ungrouped output, so it can't tell us per-step durations.
 *
 * Output rows feed {@see ServerProvisionStepRun} so the
 * journey UI can replace the static "Usually X minutes" copy with
 * data-driven averages.
 */
class ProvisionStepDurations
{
    public const STEP_END_PREFIX = '[dply-step-end] ';

    /**
     * Walk the task output, return one entry per `[dply-step-end]` line.
     *
     * The label is normalized through {@see ProvisionStepSnapshots::normalizeLabel()}
     * so resumed and non-resumed rows for the same logical step share a
     * `label_hash` — the recorder can then exclude resumed rows from
     * averages while still attributing them to the same identity.
     *
     * Malformed lines (no tab, non-numeric seconds, label-only) are
     * silently skipped — the bash format is fixed so a malformed line
     * is almost certainly a parse desync, not a real step.
     *
     * @return list<array{label: string, label_hash: string, duration_seconds: int, resumed: bool}>
     */
    public static function parse(string $output): array
    {
        if ($output === '' || ! str_contains($output, self::STEP_END_PREFIX)) {
            return [];
        }

        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
            if (! str_contains($line, self::STEP_END_PREFIX)) {
                continue;
            }

            // Step over any leading prefix nginx-style log piping might
            // have prepended (date stamps, etc.) — anchor on the marker
            // itself so we only consume what's after it.
            $payload = substr($line, strpos($line, self::STEP_END_PREFIX) + strlen(self::STEP_END_PREFIX));
            $payload = rtrim($payload, "\r\n\t ");
            if ($payload === '') {
                continue;
            }

            $fields = explode("\t", $payload);
            if (count($fields) < 2) {
                continue;
            }

            $label = trim($fields[0]);
            $secondsRaw = trim($fields[1]);
            $resumed = isset($fields[2]) && trim($fields[2]) === 'resumed';

            if ($label === '' || ! ctype_digit($secondsRaw)) {
                continue;
            }

            $normalized = ProvisionStepSnapshots::normalizeLabel($label);

            $rows[] = [
                'label' => $normalized,
                'label_hash' => ProvisionStepSnapshots::keyForLabel($normalized),
                'duration_seconds' => (int) $secondsRaw,
                'resumed' => $resumed,
            ];
        }

        return $rows;
    }
}
