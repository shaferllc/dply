<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SiteDeployment extends Model
{
    use HasUlids;

    protected $table = 'site_deployments';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_WEBHOOK = 'webhook';

    public const TRIGGER_API = 'api';

    public const TRIGGER_SYNC_PEER = 'sync_peer';

    public const TRIGGER_SCHEDULE = 'schedule';

    public const TRIGGER_RESUME = 'resume';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'site_id',
        'project_id',
        'idempotency_key',
        'trigger',
        'status',
        'git_sha',
        'release_folder',
        'resume_of_deployment_id',
        'exit_code',
        'log_output',
        'phase_results',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'phase_results' => 'array',
        ];
    }

    /**
     * Record one phase's worth of {@see DeployPhaseRunner} step results
     * into the structured phase_results column. Calling repeatedly for
     * different phases on the same deployment composes them under their
     * canonical keys (build / swap / release / restart).
     *
     * The runner returns a list of step result arrays per call; each
     * call to recordPhaseResults stores that list under its phase key.
     * If the same phase is recorded twice (re-run scenario), the new
     * list replaces the old — the UI shows the latest attempt.
     *
     * @param  list<array<string, mixed>>  $results
     */
    public function recordPhaseResults(string $phase, array $results): void
    {
        $existing = is_array($this->phase_results) ? $this->phase_results : [];
        $existing[$phase] = $results;
        $this->phase_results = $existing;
        $this->save();
    }

    /**
     * Aggregate ok flag across all recorded phases. True when every
     * recorded step is ok or skipped; false when any step has ok=false.
     */
    public function phasesAllOk(): bool
    {
        $results = is_array($this->phase_results) ? $this->phase_results : [];
        if ($results === []) {
            return false;
        }
        foreach ($results as $steps) {
            if (! is_array($steps)) {
                continue;
            }
            foreach ($steps as $step) {
                if (($step['ok'] ?? false) !== true) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Total wall-clock duration across all recorded steps.
     */
    public function phaseTotalDurationMs(): int
    {
        $total = 0;
        $results = is_array($this->phase_results) ? $this->phase_results : [];
        foreach ($results as $steps) {
            if (! is_array($steps)) {
                continue;
            }
            foreach ($steps as $step) {
                $total += (int) ($step['duration_ms'] ?? 0);
            }
        }

        return $total;
    }

    /**
     * Steps recorded for a phase, or an empty list when the phase
     * isn't in phase_results at all. Drives the dashboard's per-phase
     * step list without forcing the view into nested @php blocks.
     *
     * @return list<array<string, mixed>>
     */
    public function phaseSteps(string $phase): array
    {
        $results = is_array($this->phase_results) ? $this->phase_results : [];
        $steps = $results[$phase] ?? null;

        return is_array($steps) ? array_values($steps) : [];
    }

    /**
     * Whether the phase is recorded AND every step succeeded.
     */
    public function phaseOk(string $phase): bool
    {
        $steps = $this->phaseSteps($phase);
        if ($steps === []) {
            return false;
        }
        foreach ($steps as $step) {
            if (($step['ok'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    public function hasPhase(string $phase): bool
    {
        $results = is_array($this->phase_results) ? $this->phase_results : [];

        return is_array($results[$phase] ?? null);
    }

    /**
     * The first recorded phase (in canonical run order) that contains a failed
     * step, or null when nothing recorded failed. Drives "Retry from {phase}":
     * a failure at `build` means re-run build→release→activate; at `release`,
     * re-run release→activate. Returns null when the failure happened in an
     * unrecorded phase (env/activate-flip/…), in which case resume isn't offered.
     */
    public function failedPhase(): ?string
    {
        $results = is_array($this->phase_results) ? $this->phase_results : [];
        foreach (\App\Services\Deploy\DeployResumePlan::PHASE_ORDER as $phase) {
            $steps = $results[$phase] ?? null;
            if (! is_array($steps)) {
                continue;
            }
            foreach ($steps as $step) {
                if (($step['ok'] ?? false) !== true && ($step['skipped'] ?? false) !== true) {
                    return $phase;
                }
            }
        }

        return null;
    }

    /**
     * The phase a "resume" should restart from, or null when this deployment
     * can't be resumed. Resumable only when it failed in a recorded, resumable
     * phase ({@see DeployResumePlan::RESUMABLE_PHASES}) AND we still know which
     * release folder to re-attach to.
     *
     *   build / release — pre-cutover; the staged release was never made live.
     *   restart — post-cutover; the new release IS live but a finishing step
     *     (post-deploy command / worker restart) failed. Guarded on a recorded,
     *     succeeded `activate` so we never treat a half-flipped deploy as a
     *     clean post-cutover state.
     */
    public function resumeStartPhase(): ?string
    {
        if ($this->status !== self::STATUS_FAILED) {
            return null;
        }
        if (empty($this->release_folder)) {
            return null;
        }
        $phase = $this->failedPhase();
        if ($phase === null || ! in_array($phase, \App\Services\Deploy\DeployResumePlan::RESUMABLE_PHASES, true)) {
            return null;
        }

        // A post-cutover (restart) resume only makes sense if the cutover
        // actually happened — i.e. the symlink flipped and `current` points at
        // this release. Without a clean recorded activate we can't assume that.
        if ($phase === 'restart' && ! $this->phaseOk('activate')) {
            return null;
        }

        return $phase;
    }

    /** True when resuming this deployment re-runs work AFTER the cutover (release already live). */
    public function resumeIsPostCutover(): bool
    {
        return $this->resumeStartPhase() === 'restart';
    }

    public function isResumable(): bool
    {
        return $this->resumeStartPhase() !== null;
    }

    public function resumeOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resume_of_deployment_id');
    }

    /**
     * Tailwind class string for the per-step status pill in dashboard
     * partials. Consolidated here so Blade doesn't need a nested
     * ternary inside @php (the lexer chokes on those).
     *
     * @param  array<string, mixed>  $step
     */
    public function stepClasses(array $step): string
    {
        $skipped = ($step['skipped'] ?? false) === true;
        $ok = ($step['ok'] ?? false) === true;

        if ($skipped) {
            return 'bg-amber-100 text-amber-900';
        }

        return $ok ? 'bg-emerald-100 text-emerald-900' : 'bg-rose-100 text-rose-900';
    }

    /**
     * One-character glyph for the per-step status pill.
     *
     * @param  array<string, mixed>  $step
     */
    public function stepGlyph(array $step): string
    {
        if (($step['skipped'] ?? false) === true) {
            return '·';
        }

        return ($step['ok'] ?? false) === true ? '✓' : '✗';
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function ephemeralCredential(): HasOne
    {
        return $this->hasOne(SiteDeploymentEphemeralCredential::class);
    }
}
