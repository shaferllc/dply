<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Services\Deploy\DeployPhaseRunner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Manually run a single deploy phase against a site.
 *
 *   dply:site:run-phase <site> <phase> [--release-dir=] [--json]
 *
 * Wraps {@see DeployPhaseRunner} for ops debugging — when a deploy
 * pipeline gets stuck on one phase, run that phase in isolation to
 * see the exact step outputs and timings without re-running the
 * preceding phases.
 *
 * <phase> ∈ {build, swap, release, restart}.
 *
 * <release-dir> defaults to the site's repository_path (for simple
 * deploys, that's where code already lives). For atomic-deploy
 * sites pass `--release-dir=/var/www/<slug>/releases/<ulid>` to
 * point at a specific release.
 */
class RunSitePhaseCommand extends Command
{
    protected $signature = 'dply:site:run-phase
        {site : Site ID, slug, or name}
        {phase : One of build, swap, release, restart}
        {--release-dir= : Override the working directory (defaults to repository_path)}
        {--json : Output the runner result as JSON}';

    protected $description = 'Manually run a single deploy phase (build/swap/release/restart) against a site.';

    public function handle(DeployPhaseRunner $runner): int
    {
        $needle = (string) $this->argument('site');
        $site = $this->resolveSite($needle);
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        $phase = strtolower((string) $this->argument('phase'));
        if (! in_array($phase, SiteDeployStep::ALL_PHASES, true)) {
            $this->error('Phase must be one of: '.implode(', ', SiteDeployStep::ALL_PHASES));

            return self::FAILURE;
        }

        $releaseDir = (string) ($this->option('release-dir') ?? '');
        if ($releaseDir === '') {
            $releaseDir = trim((string) $site->repository_path) !== ''
                ? rtrim((string) $site->repository_path, '/')
                : '/var/www/'.$site->slug;
        }

        try {
            $results = match ($phase) {
                SiteDeployStep::PHASE_BUILD => $runner->runBuild($site, $releaseDir),
                SiteDeployStep::PHASE_SWAP => $runner->runSwap($site, $releaseDir),
                SiteDeployStep::PHASE_RELEASE => $runner->runRelease($site, $releaseDir),
                SiteDeployStep::PHASE_RESTART => $runner->runRestart($site),
            };
        } catch (Throwable $e) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'ok' => false,
                    'site_id' => $site->id,
                    'phase' => $phase,
                    'error' => $e->getMessage(),
                ], JSON_PRETTY_PRINT));
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        $allOk = $this->resultsAllOk($results);

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => $allOk,
                'site_id' => $site->id,
                'phase' => $phase,
                'release_dir' => $releaseDir,
                'results' => $results,
            ], JSON_PRETTY_PRINT));

            return $allOk ? self::SUCCESS : self::FAILURE;
        }

        $this->renderHumanResults($site, $phase, $releaseDir, $results, $allOk);

        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function resultsAllOk(array $results): bool
    {
        if ($results === []) {
            return true;
        }
        foreach ($results as $r) {
            if (($r['ok'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function renderHumanResults(Site $site, string $phase, string $releaseDir, array $results, bool $allOk): void
    {
        $this->newLine();
        $this->line(sprintf(
            '<fg=cyan>%s</> phase on <fg=white;options=bold>%s</> at <fg=white>%s</>',
            strtoupper($phase),
            $site->name,
            $releaseDir,
        ));
        $this->newLine();

        if ($results === []) {
            $this->warn('No steps to run for this phase.');

            return;
        }

        foreach ($results as $r) {
            $ok = (bool) ($r['ok'] ?? false);
            $skipped = (bool) ($r['skipped'] ?? false);
            $glyph = $skipped ? '·' : ($ok ? '✓' : '✗');
            $color = $skipped ? 'yellow' : ($ok ? 'green' : 'red');
            $command = (string) ($r['command'] ?? '<no command>');
            $duration = (int) ($r['duration_ms'] ?? 0);

            $this->line(sprintf('  <fg=%s>%s</> %s  <fg=gray>(%dms)</>', $color, $glyph, $command, $duration));
            $output = trim((string) ($r['output'] ?? ''));
            if (! $ok && $output !== '') {
                $this->line('     <fg=red>'.$output.'</>');
            }
        }

        $this->newLine();
        if ($allOk) {
            $this->info(sprintf('Phase %s completed.', $phase));
        } else {
            $this->error(sprintf('Phase %s failed.', $phase));
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
