<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Reclaims disk on the control plane by pruning build scratch under
 * storage/app that the deploy flows create but never clean up:
 *
 *   serverless-artifacts/<site>/<slug>-<ts>.zip   — one zip per deploy
 *   serverless-repositories/<build-…|local-launch-…> — git checkout caches
 *   task-runner/temp/*                            — task-runner scratch
 *
 * These are local files (no SSH), so the command does the work inline rather
 * than dispatching per-server jobs. Deletion is age-based: artifacts are
 * byproducts kept briefly for failed-deploy post-mortem; repository caches are
 * kept while deploys keep touching them (re-cloned on next use if pruned).
 *
 * Split-deployment caveat: the scheduler pins this to onOneServer(), but the
 * scratch lives on whichever box ran the build. In a multi-box topology where
 * builds aren't pinned to that same host, run it on each build host instead.
 */
class PruneLocalWorkspaceArtifactsCommand extends Command
{
    protected $signature = 'dply:prune-local-workspaces
        {--dry-run : Report what would be removed without deleting}
        {--artifacts-hours= : Override max age (hours) for serverless build artifacts}
        {--repositories-hours= : Override max age (hours) for serverless repository caches}
        {--task-runner-hours= : Override max age (hours) for task-runner temp}';

    protected $description = 'Reclaim disk by removing stale serverless artifacts, repository caches, and task-runner temp under storage/app.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $now = time();

        $artifactsCutoff = $now - $this->ageHours('artifacts-hours', 'artifacts_max_age_hours', 48) * 3600;
        $repositoriesCutoff = $now - $this->ageHours('repositories-hours', 'repositories_max_age_hours', 168) * 3600;
        $taskRunnerCutoff = $now - $this->ageHours('task-runner-hours', 'task_runner_max_age_hours', 24) * 3600;

        $freed = 0;
        $removed = 0;

        // serverless-artifacts/<site-id>/<slug>-<ts>.zip — prune stale zips at
        // the file level, then drop now-empty per-site directories.
        [$f, $r] = $this->pruneArtifacts(storage_path('app/serverless-artifacts'), $artifactsCutoff, $dry);
        $freed += $f;
        $removed += $r;

        // serverless-repositories/<…> — prune whole checkout caches whose most
        // recent git activity predates the window.
        [$f, $r] = $this->pruneDirectories(storage_path('app/serverless-repositories'), $repositoriesCutoff, $dry, gitAware: true);
        $freed += $f;
        $removed += $r;

        // task-runner/temp/* — short-lived scratch.
        [$f, $r] = $this->pruneDirectories(storage_path('app/task-runner/temp'), $taskRunnerCutoff, $dry, gitAware: false);
        $freed += $f;
        $removed += $r;

        $this->components->info(sprintf(
            '%s %d entr%s, %s %s.',
            $dry ? 'Would remove' : 'Removed',
            $removed,
            $removed === 1 ? 'y' : 'ies',
            $dry ? 'reclaiming' : 'reclaimed',
            $this->humanBytes($freed),
        ));

        return self::SUCCESS;
    }

    /**
     * Per-site artifact dirs hold timestamped zips; delete the stale ones and
     * remove a site dir once it's empty.
     *
     * @return array{0: int, 1: int} [bytesFreed, entriesRemoved]
     */
    private function pruneArtifacts(string $root, int $cutoff, bool $dry): array
    {
        if (! File::isDirectory($root)) {
            return [0, 0];
        }

        $freed = 0;
        $removed = 0;

        foreach (File::directories($root) as $siteDir) {
            foreach (File::allFiles($siteDir) as $file) {
                if ($file->getMTime() >= $cutoff) {
                    continue;
                }

                $freed += $file->getSize();
                $removed++;
                if (! $dry) {
                    File::delete($file->getPathname());
                }
                $this->line("  artifact  {$file->getPathname()}", null, 'v');
            }

            // Drop the per-site directory once nothing recent remains in it.
            if (! $dry && File::isEmptyDirectory($siteDir)) {
                File::deleteDirectory($siteDir);
            }
        }

        return [$freed, $removed];
    }

    /**
     * Prune whole top-level directories whose last activity predates $cutoff.
     * When $gitAware, "activity" also considers the repo/.git mtime so a site
     * that redeploys often (touching git internals, not the top dir) keeps its
     * cache.
     *
     * @return array{0: int, 1: int} [bytesFreed, entriesRemoved]
     */
    private function pruneDirectories(string $root, int $cutoff, bool $dry, bool $gitAware): array
    {
        if (! File::isDirectory($root)) {
            return [0, 0];
        }

        $freed = 0;
        $removed = 0;

        foreach (File::directories($root) as $dir) {
            if ($this->lastActivity($dir, $gitAware) >= $cutoff) {
                continue;
            }

            $freed += $this->directorySize($dir);
            $removed++;
            if (! $dry) {
                File::deleteDirectory($dir);
            }
            $this->line("  cache     {$dir}", null, 'v');
        }

        return [$freed, $removed];
    }

    /**
     * Newest mtime among the directory and (when git-aware) its repo / repo/.git
     * children — the spots git rewrites on every fetch/checkout.
     */
    private function lastActivity(string $dir, bool $gitAware): int
    {
        $candidates = [(int) (@filemtime($dir) ?: 0)];

        if ($gitAware) {
            foreach (['/repo', '/repo/.git'] as $sub) {
                if (is_dir($dir.$sub)) {
                    $candidates[] = (int) (@filemtime($dir.$sub) ?: 0);
                }
            }
        }

        return max($candidates);
    }

    private function directorySize(string $dir): int
    {
        $bytes = 0;
        foreach (File::allFiles($dir) as $file) {
            $bytes += $file->getSize();
        }

        return $bytes;
    }

    /**
     * Resolve an age in hours from the CLI override or config, clamped to ≥1 so
     * a misconfiguration can never delete freshly-written scratch.
     */
    private function ageHours(string $option, string $configKey, int $default): int
    {
        $override = $this->option($option);
        $hours = $override !== null && $override !== ''
            ? (int) $override
            : (int) config("dply.local_workspace_prune.{$configKey}", $default);

        return max(1, $hours);
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf('%.1f %s', $value, $units[$unit]);
    }
}
