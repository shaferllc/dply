<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Console;

use App\Models\SiteDeployment;
use Illuminate\Console\Command;

/**
 * Drill into a single deployment's per-phase, per-step result.
 *
 *   dply:site:show-deploy <deployment-id>
 *   dply:site:show-deploy <deployment-id> --phase=build
 *   dply:site:show-deploy <deployment-id> --output    # full output text
 *   dply:site:show-deploy <deployment-id> --json
 *
 * Reads the phase_results JSON column and renders it human-friendly.
 * Default mode prints a tree of phase → step with status + duration;
 * --output additionally includes the captured stdout/stderr from
 * each step (which can be long, so it's opt-in).
 *
 * Companion to dply:site:deploy-history (which lists deploys);
 * this drills into one deploy's audit trail.
 */
class ShowSiteDeployCommand extends Command
{
    protected $signature = 'dply:site:show-deploy
        {id : SiteDeployment ID}
        {--phase= : Limit output to a specific phase (build/swap/release/restart)}
        {--output : Include captured step output}
        {--json : Output the deployment + phase_results as JSON}';

    protected $description = 'Drill into a single deployment\'s phase + step result.';

    public function handle(): int
    {
        $id = (string) $this->argument('id');
        $deployment = SiteDeployment::query()->where('id', $id)->first();
        if ($deployment === null) {
            $this->error("Deployment not found: {$id}");

            return self::FAILURE;
        }

        $phaseFilter = $this->option('phase');
        $phaseResults = $deployment->phase_results ?? [];
        if ($phaseFilter !== null) {
            $phaseResults = array_intersect_key($phaseResults, [$phaseFilter => true]);
        }

        if ($this->option('json')) {
            $payload = [
                'deployment_id' => $deployment->id,
                'site_id' => $deployment->site_id,
                'status' => $deployment->status,
                'trigger' => $deployment->trigger,
                'started_at' => $deployment->started_at?->toIso8601String(),
                'finished_at' => $deployment->finished_at?->toIso8601String(),
                'phase_filter' => $phaseFilter,
                'phase_results' => $phaseResults,
            ];
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));

            return $deployment->status === SiteDeployment::STATUS_FAILED
                ? self::FAILURE
                : self::SUCCESS;
        }

        $this->renderHuman($deployment, $phaseResults, (bool) $this->option('output'));

        return $deployment->status === SiteDeployment::STATUS_FAILED
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $phaseResults
     */
    private function renderHuman(SiteDeployment $d, array $phaseResults, bool $showOutput): void
    {
        $this->newLine();
        $this->line(sprintf(
            '<fg=cyan>Deployment</> <fg=white;options=bold>%s</> — status: %s%s',
            $d->id,
            $this->statusColor($d->status),
            $d->trigger ? ' • trigger: '.$d->trigger : '',
        ));
        if ($d->started_at) {
            $duration = $d->finished_at
                ? $d->started_at->diffInSeconds($d->finished_at).'s'
                : 'still running';
            $this->line(sprintf('  started %s • took %s', $d->started_at->toIso8601String(), $duration));
        }

        if ($phaseResults === []) {
            $this->newLine();
            $this->line('<fg=gray>No phase results recorded.</>');

            return;
        }

        // phase_results[$phase] is a flat list of step result arrays
        // (see DeploymentRunner::run() — it passes runBuild() etc. directly
        // to recordPhaseResults). Compute "phase ok" by inspecting steps,
        // not by reading a non-existent payload['ok'] key.
        foreach ($phaseResults as $phaseName => $steps) {
            if (! is_array($steps)) {
                continue;
            }
            $this->newLine();
            $ok = $this->stepsAllOk($steps);
            $marker = $ok ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->line(sprintf('%s <fg=cyan>%s</>', $marker, $phaseName));
            foreach ($steps as $step) {
                if (! is_array($step)) {
                    continue;
                }
                $this->renderStep($step, $showOutput);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $steps
     */
    private function stepsAllOk(array $steps): bool
    {
        if ($steps === []) {
            return false;
        }
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            if (($step['ok'] ?? false) !== true && ($step['skipped'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function renderStep(array $step, bool $showOutput): void
    {
        $ok = (bool) ($step['ok'] ?? false);
        $skipped = (bool) ($step['skipped'] ?? false);
        $marker = $skipped ? '<fg=gray>○</>' : ($ok ? '<fg=green>●</>' : '<fg=red>●</>');
        $name = (string) ($step['step_type'] ?? 'step');
        $duration = isset($step['duration_ms'])
            ? '  ('.$step['duration_ms'].'ms)'
            : '';
        $this->line(sprintf('  %s %s%s', $marker, $name, $duration));

        $cmd = (string) ($step['command'] ?? '');
        if ($cmd !== '') {
            $this->line('    <fg=gray>$ '.$this->truncate($cmd, 200).'</>');
        }

        if ($showOutput) {
            $output = (string) ($step['output'] ?? '');
            if ($output !== '') {
                foreach (explode("\n", trim($output)) as $line) {
                    $this->line('      '.$line);
                }
            }
        }
    }

    private function statusColor(string $status): string
    {
        return match ($status) {
            SiteDeployment::STATUS_SUCCESS => '<fg=green>success</>',
            SiteDeployment::STATUS_FAILED => '<fg=red>failed</>',
            SiteDeployment::STATUS_RUNNING => '<fg=yellow>running</>',
            SiteDeployment::STATUS_SKIPPED => '<fg=gray>skipped</>',
            default => $status,
        };
    }

    private function truncate(string $s, int $max): string
    {
        return strlen($s) > $max ? substr($s, 0, $max - 1).'…' : $s;
    }
}
