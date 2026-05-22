<?php

declare(strict_types=1);

namespace App\Support\Servers;

class ProvisionStepSnapshots
{
    public const SCRIPT_STEP_PREFIX = '[dply-step] ';

    /**
     * Suffix appended by ServerProvisionCommandBuilder::withStep() when a
     * marker file already exists from a prior run. The bash script emits
     * BOTH the original marker AND the resumed-skip marker, so naive
     * extraction sees each step twice ("Installing MySQL" AND "Installing
     * MySQL (resumed: already done)") in the Up-next list. Stripping
     * this suffix collapses them to one logical step.
     */
    private const RESUMED_SKIP_SUFFIX = ' (resumed: already done)';

    public static function keyForLabel(string $label): string
    {
        return 'script_'.md5(self::normalizeLabel($label));
    }

    /**
     * Strip the resumed-skip suffix so re-run output (which emits the
     * skip marker) maps to the same logical step as the first-run output
     * (which emits the plain marker).
     */
    public static function normalizeLabel(string $label): string
    {
        if (str_ends_with($label, self::RESUMED_SKIP_SUFFIX)) {
            return rtrim(substr($label, 0, -strlen(self::RESUMED_SKIP_SUFFIX)));
        }

        return $label;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @return array<string, array{label:string,output?:string,updated_at?:string}>
     */
    public static function merge(array $existing, ?string $scriptContent, ?string $output): array
    {
        $labels = self::extractLabels(trim((string) $scriptContent) !== '' ? (string) $scriptContent : (string) $output);

        if ($labels === []) {
            return $existing;
        }

        $capturedOutput = self::extractOutputsByLabel((string) $output);
        $snapshots = $existing;
        $updatedAt = now()->toIso8601String();

        foreach ($labels as $label) {
            $key = self::keyForLabel($label);
            $snapshot = is_array($snapshots[$key] ?? null) ? $snapshots[$key] : [];
            $snapshot['label'] = $label;

            $stepOutput = trim((string) ($capturedOutput[$label] ?? ''));
            if ($stepOutput !== '') {
                $snapshot['output'] = $stepOutput;
                $snapshot['updated_at'] = $updatedAt;
            }

            $snapshots[$key] = $snapshot;
        }

        return $snapshots;
    }

    /**
     * @return list<string>
     */
    public static function extractLabels(string $content): array
    {
        $labels = [];

        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            if (! str_contains($line, self::SCRIPT_STEP_PREFIX)) {
                continue;
            }

            $label = trim(str_replace(["echo '", 'echo "', "'", '"'], '', strstr($line, self::SCRIPT_STEP_PREFIX) ?: ''));
            $label = preg_replace('/^\[dply-step\]\s*/', '', $label ?? '');
            $label = self::normalizeLabel(trim((string) $label));

            if ($label !== '' && ! in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    public static function extractOutputsByLabel(string $output): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $output) ?: [];
        $captured = [];
        $activeLabel = null;

        foreach ($lines as $line) {
            if (str_contains($line, self::SCRIPT_STEP_PREFIX)) {
                $label = trim(str_replace(self::SCRIPT_STEP_PREFIX, '', strstr($line, self::SCRIPT_STEP_PREFIX) ?: ''));
                $label = self::normalizeLabel($label);
                $activeLabel = $label !== '' ? $label : null;

                if ($activeLabel !== null) {
                    $captured[$activeLabel] ??= [];
                }

                continue;
            }

            if ($activeLabel === null || trim($line) === '') {
                continue;
            }

            $captured[$activeLabel][] = $line;
        }

        return array_map(
            static fn (array $stepLines): string => implode("\n", $stepLines),
            $captured,
        );
    }
}
