<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployment;
use App\Models\SiteDeployStep;
use Illuminate\Support\Collection;
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
    /**
     * Canonical pipeline phases shown on the Deploy tab, in execution/display
     * order. Release-phase steps now run BEFORE the activate/cutover (the
     * deployer flips `current` last, so a failed deploy never goes live), so
     * Release precedes Activate here — keeping this list in step with the real
     * order also keeps the "currently running" indicator accurate.
     */
    private const PHASES = [
        'clone' => 'Clone & fetch',
        'build' => 'Build',
        'resources' => 'Verify resources',
        'release' => 'Release',
        'activate' => 'Activate',
    ];

    /**
     * @return list<array{key: string, label: string, status: string, duration_ms: int, steps: list<array<string, mixed>>}>
     */
    public static function forDeployment(Site $site, ?SiteDeployment $latest): array
    {
        $running = $latest !== null && $latest->status === SiteDeployment::STATUS_RUNNING;

        // Use the cached relation property (not ->get()) so repeated reads in the
        // same request — e.g. this timeline plus SitePipelineAdvisor on the same
        // $site instance — share one query instead of each hitting the DB.
        $site->loadMissing('deploySteps');

        $phaseDefs = self::PHASES;
        // The pre-cutover RESOURCES gate only exists for sites that have a
        // networked resource binding to probe (or a deploy that recorded it).
        // Drop the row otherwise so static/binding-less sites don't show an
        // always-empty "Verify resources" phase.
        $site->loadMissing('bindings');
        $hasResources = ($latest !== null && $latest->hasPhase('resources'))
            || $site->bindings->contains(static fn ($b): bool => BindingReachability::isNetworked((string) $b->type));
        if (! $hasResources) {
            unset($phaseDefs['resources']);
        }

        // Canonical phases, plus a POST-CUTOVER Restart phase when the site has
        // restart-phase steps (queue:restart / horizon:terminate / custom worker
        // restarts) or this deploy recorded one (dply's managed reload also lands
        // under 'restart'). Omitted otherwise so static sites don't show an empty
        // pending Restart row.
        $hasRestart = ($latest !== null && $latest->hasPhase('restart'))
            || $site->deploySteps->contains(static fn ($s): bool => (string) $s->phase === SiteDeployStep::PHASE_RESTART);
        if ($hasRestart) {
            $phaseDefs['restart'] = 'Restart';
        }

        // Post-cutover HTTP validation gate. Shown only when this deploy recorded
        // it (the checker is per-site opt-out, and older deploys predate it), so a
        // FAILED health check renders as a red phase with the cause — the timeline
        // no longer reads all-green when the deploy actually failed here.
        if ($latest !== null && $latest->hasPhase('health')) {
            $phaseDefs['health'] = 'Health check';
        }

        // The phase currently executing. With incremental per-step recording a
        // phase is recorded WHILE it runs, so prefer the phase that still has a
        // step flagged `running`; only then fall back to the first canonical
        // phase the deployment hasn't recorded yet (deployer records
        // clone → build → release → activate → restart in order).
        $runningPhase = null;
        if ($running) {
            foreach (array_keys($phaseDefs) as $key) {
                if (! $latest->hasPhase($key)) {
                    continue;
                }
                foreach ($latest->phaseSteps($key) as $recordedStep) {
                    if (($recordedStep['running'] ?? false) === true) {
                        $runningPhase = $key;
                        break 2;
                    }
                }
            }
            if ($runningPhase === null) {
                foreach (array_keys($phaseDefs) as $key) {
                    if (! $latest->hasPhase($key)) {
                        $runningPhase = $key;
                        break;
                    }
                }
            }
        }

        $finished = $latest !== null && in_array($latest->status, [SiteDeployment::STATUS_SUCCESS, SiteDeployment::STATUS_FAILED], true);
        // Preview configured steps (as "queued") while a deploy is pending or
        // running and a phase hasn't recorded yet, so the whole pipeline shows.
        $previewing = $latest === null || ! $finished;

        $configuredByPhase = $site->deploySteps->groupBy(static fn ($s): string => (string) $s->phase);
        $hooksByPhase = self::hooksByPhase($site);

        // Show every phase with its status — clone → build → release → activate
        // (→ restart) — so the full pipeline and all statuses are always visible.
        $phases = [];
        foreach ($phaseDefs as $key => $label) {
            $recorded = $latest !== null && $latest->hasPhase($key);
            $steps = $recorded ? $latest->phaseSteps($key) : [];
            $configured = $configuredByPhase->get($key) ?? collect();
            $hooks = $hooksByPhase->get($key) ?? collect();

            $status = self::statusFor($recorded, $steps, $running, $runningPhase === $key);
            // A phase that recorded with no steps still ran — show it as done on
            // a finished deploy rather than a stuck "skipped".
            if ($recorded && $steps === [] && $status === 'skipped' && $finished) {
                $status = 'success';
            }

            $items = array_map(static fn (array $step): array => self::stepView($latest, $step), $steps);

            // Not recorded yet but configured → preview the upcoming steps so the
            // pipeline reads as a plan, not a blank phase.
            if ($steps === [] && $previewing && $configured->isNotEmpty()) {
                foreach ($configured as $cfg) {
                    $items[] = self::configuredStepView($cfg);
                }
            }

            foreach ($hooks as $hook) {
                $items[] = self::hookView($hook, $status, $previewing);
            }

            $durationMs = 0;
            foreach ($steps as $step) {
                $durationMs += (int) ($step['duration_ms'] ?? 0);
            }

            $phases[] = [
                'key' => $key,
                'label' => __($label),
                'status' => $status,
                'duration_ms' => $durationMs,
                'steps' => $items,
            ];
        }

        return $phases;
    }

    /**
     * A configured-but-not-yet-run pipeline step rendered as a "queued" item.
     *
     * @return array<string, mixed>
     */
    private static function configuredStepView(SiteDeployStep $step): array
    {
        $type = (string) $step->step_type;
        $label = $type === SiteDeployStep::TYPE_CUSTOM
            ? (filled($step->custom_command) ? Str::limit((string) $step->custom_command, 60) : __('Custom command'))
            : Str::headline($type);

        return [
            'label' => $label,
            'step_type' => $type,
            'duration_ms' => 0,
            'skipped' => false,
            'ok' => false,
            'pending' => true,
            'running' => false,
            'output' => '',
            'glyph' => '·',
            'glyph_classes' => 'bg-brand-sand/60 text-brand-ink',
            'id' => (string) $step->id,
        ];
    }

    /**
     * Configured deploy hooks bucketed into the canonical phase they fire
     * around, so they can be listed alongside that phase's steps.
     *
     * @return Collection<string, Collection<int, SiteDeployHook>>
     */
    private static function hooksByPhase(Site $site): Collection
    {
        /** @var Collection<string, Collection<int, SiteDeployHook>> $map */
        $map = (new Collection(array_keys(self::PHASES)))
            ->mapWithKeys(static fn (string $phase): array => [$phase => new Collection]);

        $site->loadMissing('deployHooks.anchorStep');
        foreach ($site->deployHooks as $hook) {
            $phase = match ($hook->anchor) {
                SiteDeployHook::ANCHOR_BEFORE_CLONE, SiteDeployHook::ANCHOR_AFTER_CLONE => 'clone',
                SiteDeployHook::ANCHOR_BEFORE_ACTIVATE, SiteDeployHook::ANCHOR_AFTER_ACTIVATE => 'activate',
                SiteDeployHook::ANCHOR_AFTER_STEP => (string) ($hook->anchorStep?->phase ?: 'build'),
                default => 'build',
            };
            if (! $map->has($phase)) {
                $map->put($phase, new Collection);
            }

            /** @var Collection<int, SiteDeployHook> $phaseHooks */
            $phaseHooks = $map->get($phase);
            $phaseHooks->push($hook);
        }

        return $map;
    }

    /**
     * A configured hook rendered as a timeline item. Hooks aren't recorded as
     * their own step results (their output folds into the adjacent step), so
     * status is inferred from the phase: ran when the phase succeeded, shown as
     * not-run otherwise. Never rendered as "failed".
     *
     * @return array<string, mixed>
     */
    private static function hookView(SiteDeployHook $hook, string $phaseStatus, bool $previewing = false): array
    {
        $ran = $phaseStatus === 'success';
        $pending = ! $ran && $previewing;
        $label = $hook->pillLabel();
        if (filled($hook->label)) {
            $label .= ' · '.$hook->label;
        }

        return [
            'label' => $label,
            'step_type' => 'hook',
            'duration_ms' => 0,
            'skipped' => ! $ran && ! $pending,
            'ok' => $ran,
            'pending' => $pending,
            'running' => false,
            'output' => '',
            'glyph' => $ran ? '✓' : '·',
            'glyph_classes' => $ran ? 'bg-emerald-100 text-emerald-800' : 'bg-brand-sand/60 text-brand-ink',
            'id' => (string) $hook->id,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private static function statusFor(bool $recorded, array $steps, bool $running, bool $isRunningPhase): string
    {
        if ($recorded) {
            // Incremental recording: an in-flight or still-queued step means the
            // phase is mid-run, not finished — judge that before failure/success.
            foreach ($steps as $step) {
                if (($step['running'] ?? false) === true) {
                    return 'running';
                }
            }

            if ($steps === []) {
                // Recorded with no steps — the phase ran but had nothing to
                // do (e.g. a build phase with no configured steps, or the
                // activate no-op on simple deploys).
                return 'skipped';
            }

            $hasPending = false;
            $hasOk = false;
            foreach ($steps as $step) {
                if (($step['pending'] ?? false) === true) {
                    $hasPending = true;

                    continue;
                }
                if (($step['skipped'] ?? false) === true) {
                    continue;
                }
                if (($step['ok'] ?? false) !== true) {
                    return 'failed';
                }
                $hasOk = true;
            }

            // Still-queued steps → the phase is running. Otherwise: a phase whose
            // every step was SKIPPED (e.g. the no-op Activate on a flat deploy)
            // is 'skipped', NOT 'success' — a green "done" check on a phase that
            // never actually ran reads as completed, and worse, completed out of
            // order (Activate is last but skips early on simple deploys).
            if ($hasPending) {
                return 'running';
            }

            return $hasOk ? 'success' : 'skipped';
        }

        if ($running && $isRunningPhase) {
            return 'running';
        }

        return 'pending';
    }

    /**
     * @param  array<string, mixed> $step
     * @return array<string, mixed>
     */
    private static function stepView(?SiteDeployment $latest, array $step): array
    {
        $running = ($step['running'] ?? false) === true;
        $pending = ($step['pending'] ?? false) === true;

        return [
            'label' => self::stepLabel($step),
            'step_type' => (string) ($step['step_type'] ?? 'step'),
            'duration_ms' => (int) ($step['duration_ms'] ?? 0),
            'skipped' => ($step['skipped'] ?? false) === true,
            'ok' => ($step['ok'] ?? false) === true,
            'pending' => $pending,
            'running' => $running,
            'output' => trim((string) ($step['output'] ?? '')),
            // Running steps get the spinner glyph; others use the model's glyph.
            'glyph' => $running ? '⟳' : ($latest?->stepGlyph($step) ?? '·'),
            'glyph_classes' => $running ? 'bg-amber-100 text-amber-800' : ($latest?->stepClasses($step) ?? 'bg-brand-sand/60 text-brand-ink'),
            'id' => (string) ($step['step_id'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed> $step
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
            'resource' => $command !== '' ? $command : __('Resource reachability'),
            'post_deploy' => __('Post-deploy command'),
            'custom' => $command !== '' ? Str::limit($command, 60) : __('Custom command'),
            '' => __('Step'),
            default => Str::headline($type),
        };
    }
}
