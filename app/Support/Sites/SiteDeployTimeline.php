<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Support\Str;

/**
 * Builds the Deploy-tab phase timeline view-model from a site's pipeline
 * and its latest deployment's recorded {@see SiteDeployment::$phase_results}.
 *
 * The timeline mirrors the pipeline editor's vocabulary — Clone → Build →
 * Activate → Release — rather than the internal build/swap/release/restart
 * phase keys, and overlays each phase with live per-step status so the panel
 * can show "Build ✓ (Composer install, npm ci)" while a deploy runs.
 */
final class SiteDeployTimeline
{
    /** Canonical pipeline phases shown on the Deploy tab, in display order. */
    private const PHASES = [
        'clone' => 'Clone & fetch',
        'build' => 'Build',
        'activate' => 'Activate',
        'release' => 'Release',
    ];

    /**
     * @return list<array{key: string, label: string, status: string, duration_ms: int, steps: list<array<string, mixed>>}>
     */
    public static function forDeployment(Site $site, ?SiteDeployment $latest): array
    {
        $running = $latest !== null && $latest->status === SiteDeployment::STATUS_RUNNING;

        // The phase currently executing is the first canonical phase the
        // running deployment hasn't recorded yet — the deployer records
        // clone → build → activate → release in order as it goes.
        $runningPhase = null;
        if ($running) {
            foreach (array_keys(self::PHASES) as $key) {
                if (! $latest->hasPhase($key)) {
                    $runningPhase = $key;
                    break;
                }
            }
        }

        $phases = [];
        foreach (self::PHASES as $key => $label) {
            $recorded = $latest !== null && $latest->hasPhase($key);
            $steps = $recorded ? $latest->phaseSteps($key) : [];
            $durationMs = 0;
            foreach ($steps as $step) {
                $durationMs += (int) ($step['duration_ms'] ?? 0);
            }

            $phases[] = [
                'key' => $key,
                'label' => __($label),
                'status' => self::statusFor($recorded, $steps, $running, $runningPhase === $key),
                'duration_ms' => $durationMs,
                'steps' => array_map(static fn (array $step): array => self::stepView($latest, $step), $steps),
            ];
        }

        return $phases;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private static function statusFor(bool $recorded, array $steps, bool $running, bool $isRunningPhase): string
    {
        if ($recorded) {
            if ($steps === []) {
                // Recorded with no steps — the phase ran but had nothing to
                // do (e.g. a build phase with no configured steps, or the
                // activate no-op on simple deploys).
                return 'skipped';
            }
            foreach ($steps as $step) {
                if (($step['skipped'] ?? false) !== true && ($step['ok'] ?? false) !== true) {
                    return 'failed';
                }
            }

            return 'success';
        }

        if ($running && $isRunningPhase) {
            return 'running';
        }

        return 'pending';
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private static function stepView(?SiteDeployment $latest, array $step): array
    {
        return [
            'label' => self::stepLabel($step),
            'step_type' => (string) ($step['step_type'] ?? 'step'),
            'duration_ms' => (int) ($step['duration_ms'] ?? 0),
            'skipped' => ($step['skipped'] ?? false) === true,
            'ok' => ($step['ok'] ?? false) === true,
            'output' => trim((string) ($step['output'] ?? '')),
            'glyph' => $latest?->stepGlyph($step) ?? '·',
            'glyph_classes' => $latest?->stepClasses($step) ?? 'bg-brand-sand/60 text-brand-ink',
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private static function stepLabel(array $step): string
    {
        $type = (string) ($step['step_type'] ?? '');
        $command = trim((string) ($step['command'] ?? ''));

        return match ($type) {
            'clone' => __('Clone & fetch'),
            'activate' => __('Activate'),
            'swap' => __('Swap'),
            'restart' => __('Restart'),
            'post_deploy' => __('Post-deploy command'),
            'custom' => $command !== '' ? Str::limit($command, 60) : __('Custom command'),
            '' => __('Step'),
            default => Str::headline($type),
        };
    }
}
