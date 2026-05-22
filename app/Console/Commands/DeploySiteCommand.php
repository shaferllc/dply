<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Deploy\DeploymentRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Run a full deployment for a site via {@see DeploymentRunner}.
 *
 *   dply:site:deploy <site> [--release-dir=] [--trigger=manual] [--json]
 *
 * Walks all four phases — build → swap → release → restart — against
 * the release directory, persisting per-phase results to a new
 * SiteDeployment row. Useful for:
 *   - kicking a deploy from a CI workflow without going through the
 *     webhook + queue path
 *   - re-running a deploy on a specific release dir for recovery
 *   - end-to-end smoke testing the deploy pipeline against a known
 *     release without touching the queue worker
 *
 * Synchronous (operator sees per-phase output land as it streams).
 * On any phase failure, status flips to failed and partial
 * phase_results stay captured for forensics.
 */
class DeploySiteCommand extends Command
{
    protected $signature = 'dply:site:deploy
        {site : Site ID, slug, or name}
        {--release-dir= : Override the working directory (defaults to repository_path)}
        {--trigger=manual : Tag the deployment with a trigger source (manual, ci, webhook, …)}
        {--json : Output the runner result as JSON}';

    protected $description = 'Run a full deployment (build → swap → release → restart) for a site.';

    public function handle(DeploymentRunner $runner): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $releaseDir = (string) ($this->option('release-dir') ?? '');
        if ($releaseDir === '') {
            $releaseDir = trim((string) $site->repository_path) !== ''
                ? rtrim((string) $site->repository_path, '/')
                : '/var/www/'.$site->slug;
        }

        $trigger = (string) ($this->option('trigger') ?? 'manual');

        $deployment = SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'idempotency_key' => 'dep-cli-'.(string) Str::ulid(),
            'trigger' => $trigger,
            'status' => SiteDeployment::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            $result = $runner->run($deployment, $releaseDir);
        } catch (Throwable $e) {
            $deployment->update([
                'status' => SiteDeployment::STATUS_FAILED,
                'finished_at' => now(),
            ]);
            if ($this->option('json')) {
                $this->line(json_encode([
                    'ok' => false,
                    'site_id' => $site->id,
                    'deployment_id' => $deployment->id,
                    'error' => $e->getMessage(),
                ], JSON_PRETTY_PRINT));
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => $result['ok'],
                'site_id' => $site->id,
                'deployment_id' => $deployment->id,
                'release_dir' => $releaseDir,
                'phases' => $result['phases'],
                'total_duration_ms' => $result['total_duration_ms'],
            ], JSON_PRETTY_PRINT));

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $this->renderHumanResults($site, $deployment->id, $releaseDir, $result);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{ok: bool, phases: array<string, list<array<string, mixed>>>, total_duration_ms: int}  $result
     */
    private function renderHumanResults(Site $site, string $deploymentId, string $releaseDir, array $result): void
    {
        $this->newLine();
        $this->line(sprintf(
            '<fg=cyan>Deploy</> <fg=white;options=bold>%s</> at <fg=white>%s</> (deployment <fg=gray>%s</>)',
            $site->name,
            $releaseDir,
            $deploymentId,
        ));
        $this->newLine();

        foreach ($result['phases'] as $phase => $steps) {
            if ($steps === []) {
                continue;
            }
            $this->line('<fg=cyan>'.strtoupper((string) $phase).'</>');
            foreach ($steps as $step) {
                $ok = (bool) ($step['ok'] ?? false);
                $skipped = (bool) ($step['skipped'] ?? false);
                $glyph = $skipped ? '·' : ($ok ? '✓' : '✗');
                $color = $skipped ? 'yellow' : ($ok ? 'green' : 'red');
                $this->line(sprintf(
                    '  <fg=%s>%s</> %s  <fg=gray>(%dms)</>',
                    $color,
                    $glyph,
                    (string) ($step['command'] ?? '<no command>'),
                    (int) ($step['duration_ms'] ?? 0),
                ));
                $output = trim((string) ($step['output'] ?? ''));
                if (! $ok && $output !== '') {
                    $this->line('     <fg=red>'.$output.'</>');
                }
            }
        }

        $this->newLine();
        if ($result['ok']) {
            $this->info(sprintf('Deployment succeeded in %.1fs.', $result['total_duration_ms'] / 1000));
        } else {
            $this->error(sprintf('Deployment failed after %.1fs.', $result['total_duration_ms'] / 1000));
        }
    }

    private function resolveSite(string $needle): ?Site
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Site::query()->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
