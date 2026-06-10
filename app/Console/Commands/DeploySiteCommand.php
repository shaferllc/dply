<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Deploy\DeploymentRunner;
use App\Services\Sites\SiteGitDeployer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Run a full deployment for a site.
 *
 *   dply:site:deploy <site> [--release-dir=] [--trigger=manual] [--json]
 *
 * Engine selection mirrors the queue/UI path so the CLI can never diverge
 * from a real deploy:
 *   - ATOMIC sites go through {@see SiteGitDeployer} → AtomicSiteDeployer,
 *     which clones into a fresh `releases/<ts>` dir, writes .env via the
 *     site's `env_file_path` and flips `current` to the new release. This is
 *     the ONLY correct path for atomic sites — using DeploymentRunner with a
 *     release dir defaulting to `repository_path` would flip `current` at the
 *     repo ROOT and config:cache from the root .env (the prod-clobber bug).
 *   - SIMPLE sites use {@see DeploymentRunner} (in-place build at the repo
 *     path; the swap phase is a no-op).
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

        // Atomic sites MUST use the real atomic engine — it owns release-dir
        // creation and the `current` flip. DeploymentRunner with a root
        // release dir would clobber the live checkout. Simple sites stay on
        // the in-place DeploymentRunner path.
        if ($site->isAtomicDeploys()) {
            return $this->runAtomic($site, $deployment, $releaseDir);
        }

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
     * Drive an atomic deploy through the same engine the queue/UI path uses.
     * SiteGitDeployer records per-phase results on the deployment row as it
     * goes and throws on any phase failure.
     */
    private function runAtomic(Site $site, SiteDeployment $deployment, string $releaseDir): int
    {
        try {
            $result = app(SiteGitDeployer::class)->run($site, $deployment);
            $deployment->update([
                'status' => SiteDeployment::STATUS_SUCCESS,
                'git_sha' => $result['sha'] ?? null,
                'exit_code' => 0,
                'finished_at' => now(),
            ]);
            $site->forceFill(['last_deploy_at' => now()])->save();
        } catch (Throwable $e) {
            $deployment->update([
                'status' => SiteDeployment::STATUS_FAILED,
                'exit_code' => 1,
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

        $phases = (array) ($deployment->fresh()->phase_results ?? []);

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => true,
                'site_id' => $site->id,
                'deployment_id' => $deployment->id,
                'sha' => $result['sha'] ?? null,
                'phases' => $phases,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderHumanResults($site, $deployment->id, $releaseDir, [
            'ok' => true,
            'phases' => $phases,
            'total_duration_ms' => $this->sumPhaseDurations($phases),
        ]);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $phases
     */
    private function sumPhaseDurations(array $phases): int
    {
        $total = 0;
        foreach ($phases as $steps) {
            foreach ((array) $steps as $step) {
                $total += (int) ($step['duration_ms'] ?? 0);
            }
        }

        return $total;
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
                    (string) ($step['command'] ?? $step['step_type'] ?? '<step>'),
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
