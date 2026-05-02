<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteDeployment extends Model
{
    use HasUlids;

    protected $table = 'site_deployments';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_WEBHOOK = 'webhook';

    public const TRIGGER_API = 'api';

    public const TRIGGER_SYNC_PEER = 'sync_peer';

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

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
