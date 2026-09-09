<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Console\Command;

/**
 * Show a deployment's per-step results and log.
 *
 *   dply:deploy:log dply.io
 *   dply:deploy:log dply.io --phase=restart
 *   dply:deploy:log --deployment=01k... --tail=400
 *   dply:deploy:log dply.io --json
 *
 * Exists because the deploy failures say "See the deployment log for details"
 * and, until now, there was no way to see it outside the web UI:
 *
 *   RuntimeException: Deploy failed during the restart phase.
 *   See the deployment log for details. SiteGitDeployer.php:374
 *
 * That message names the PHASE but not the step or its output, and the two
 * restarts in a deploy are different things — dply's managed supervisor
 * restart runs first, then the site's own user-authored Restart block. Only
 * the second one raises that error, so the useful detail is the failing
 * step's command and stdout, which is what this prints first.
 *
 * Defaults to the most recent deployment for the site. Read-only.
 * Exits 1 when the deployment being shown did not succeed.
 */
class DeployLogCommand extends Command
{
    protected $signature = 'dply:deploy:log
        {site? : Site ID or name (omit when using --deployment)}
        {--deployment= : A specific deployment ID}
        {--phase= : Only show one phase (build, release, restart, ...)}
        {--tail=120 : Lines of raw log to print (0 for none)}
        {--json : Output as JSON}';

    protected $description = 'Show the step results and log for a site deployment.';

    public function handle(): int
    {
        $deployment = $this->resolve();
        if ($deployment === null) {
            return self::FAILURE;
        }

        $phases = $deployment->phase_results ?? [];
        $only = (string) ($this->option('phase') ?? '');
        if ($only !== '') {
            $phases = array_intersect_key($phases, [$only => true]);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'deployment_id' => $deployment->id,
                'site' => $deployment->site?->name,
                'status' => $deployment->status,
                'exit_code' => $deployment->exit_code,
                'git_sha' => $deployment->git_sha,
                'started_at' => $deployment->started_at?->toIso8601String(),
                'finished_at' => $deployment->finished_at?->toIso8601String(),
                'phases' => $phases,
                'log_output' => $deployment->log_output,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $deployment->status === SiteDeployment::STATUS_SUCCESS ? self::SUCCESS : self::FAILURE;
        }

        $this->components->info(sprintf(
            '%s — deployment %s (%s)',
            $deployment->site->name,
            $deployment->id,
            $deployment->status,
        ));
        $this->components->twoColumnDetail('commit', (string) ($deployment->git_sha ?: '—'));
        $this->components->twoColumnDetail('started', (string) ($deployment->started_at?->toDateTimeString() ?: '—'));
        $this->components->twoColumnDetail('finished', (string) ($deployment->finished_at?->toDateTimeString() ?: '—'));

        // Failing steps first: with a long pipeline the one broken step is
        // otherwise buried under dozens of green ones.
        $failed = [];
        foreach ($phases as $phase => $steps) {
            foreach (is_array($steps) ? $steps : [] as $step) {
                if (is_array($step) && ($step['ok'] ?? true) === false && ($step['skipped'] ?? false) !== true) {
                    $failed[] = [$phase, $step];
                }
            }
        }

        if ($failed !== []) {
            $this->newLine();
            $this->components->error(sprintf('%d failing step(s):', count($failed)));
            foreach ($failed as [$phase, $step]) {
                $this->newLine();
                $this->line(sprintf(
                    '  <fg=red;options=bold>%s / %s</>',
                    $phase,
                    (string) ($step['step_id'] ?? $step['step_type'] ?? '?'),
                ));
                if (filled($step['command'] ?? null)) {
                    $this->line('  <fg=gray>$</> '.(string) $step['command']);
                }
                $output = trim((string) ($step['output'] ?? ''));
                if ($output !== '') {
                    foreach (explode("\n", $output) as $line) {
                        $this->line('    '.$line);
                    }
                }
            }
        }

        if ($phases !== []) {
            $this->newLine();
            $this->table(
                ['phase', 'step', 'result', 'ms'],
                $this->stepRows($phases),
            );
        }

        $tail = (int) $this->option('tail');
        if ($tail > 0 && filled($deployment->log_output)) {
            $lines = explode("\n", (string) $deployment->log_output);
            $this->newLine();
            $this->components->info(sprintf('last %d log line(s):', min($tail, count($lines))));
            foreach (array_slice($lines, -$tail) as $line) {
                $this->line('  '.$line);
            }
        }

        return $deployment->status === SiteDeployment::STATUS_SUCCESS ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $phases
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function stepRows(array $phases): array
    {
        $rows = [];
        foreach ($phases as $phase => $steps) {
            foreach (is_array($steps) ? $steps : [] as $step) {
                if (! is_array($step)) {
                    continue;
                }
                $rows[] = [
                    (string) $phase,
                    (string) ($step['step_id'] ?? $step['step_type'] ?? '?'),
                    match (true) {
                        ($step['skipped'] ?? false) === true => 'skipped',
                        ($step['ok'] ?? true) === false => 'FAILED',
                        default => 'ok',
                    },
                    (string) ($step['duration_ms'] ?? '—'),
                ];
            }
        }

        return $rows;
    }

    private function resolve(): ?SiteDeployment
    {
        $id = (string) ($this->option('deployment') ?? '');
        if ($id !== '') {
            $deployment = SiteDeployment::with('site')->find($id);
            if ($deployment === null) {
                $this->components->error('Deployment not found: '.$id);
            }

            return $deployment;
        }

        $needle = (string) ($this->argument('site') ?? '');
        if ($needle === '') {
            $this->components->error('Pass a site, or --deployment=<id>.');

            return null;
        }

        $site = Site::query()
            ->where('id', $needle)
            ->orWhere('name', $needle)
            ->first();

        if ($site === null) {
            $this->components->error('Site not found: '.$needle);

            return null;
        }

        $deployment = SiteDeployment::with('site')
            ->where('site_id', $site->id)
            ->orderByDesc('created_at')
            ->first();

        if ($deployment === null) {
            $this->components->error('No deployments recorded for '.$site->name);
        }

        return $deployment;
    }
}
