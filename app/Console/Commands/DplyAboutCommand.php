<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Print version + environment info for dply itself.
 *
 *   dply:about
 *   dply:about --json
 *
 * Useful when filing a bug report ("what versions are you running?")
 * or for sanity-checking a production install. Reports:
 *   - dply version (from VERSION file or git short sha)
 *   - Laravel + PHP versions
 *   - Database driver
 *   - Environment (production / staging / local)
 *   - Counts: servers, sites, deployments, engines
 *   - dply:* command count (so operators know how big the catalog is)
 *
 * No SSH; pure local introspection.
 */
class DplyAboutCommand extends Command
{
    protected $signature = 'dply:about {--json : Output as JSON}';

    protected $description = 'Print dply version, environment, and fleet counts.';

    public function handle(): int
    {
        $about = [
            'dply' => [
                'version' => $this->dplyVersion(),
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
                'environment' => app()->environment(),
                'database_driver' => config('database.default'),
            ],
            'commands' => [
                'dply_total' => $this->dplyCommandCount(),
            ],
            'fleet' => [
                'servers' => Server::query()->count(),
                'sites' => Site::query()->count(),
                'deployments_total' => SiteDeployment::query()->count(),
                'database_engines_registered' => ServerDatabaseEngine::query()->count(),
                'edge_sites' => Site::query()->whereNotNull('container_backend')->count(),
                'edge_source_mode_sites' => Site::query()
                    ->whereNotNull('container_backend')
                    ->whereNotNull('meta->container->source->repo')
                    ->count(),
                'edge_preview_sites' => Site::query()
                    ->whereNotNull('container_backend')
                    ->whereNotNull('meta->container->preview_parent_site_id')
                    ->count(),
            ],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($about, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderHuman($about);

        return self::SUCCESS;
    }

    private function dplyVersion(): string
    {
        $versionFile = base_path('VERSION');
        if (is_file($versionFile) && is_readable($versionFile)) {
            $contents = trim((string) file_get_contents($versionFile));
            if ($contents !== '') {
                return $contents;
            }
        }

        if (is_dir(base_path('.git'))) {
            $sha = @shell_exec('git -C '.escapeshellarg(base_path()).' rev-parse --short HEAD 2>/dev/null');
            if (is_string($sha) && trim($sha) !== '') {
                return 'git-'.trim($sha);
            }
        }

        return 'unknown';
    }

    private function dplyCommandCount(): int
    {
        $count = 0;
        foreach (Artisan::all() as $name => $_) {
            if (str_starts_with($name, 'dply:')) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $about
     */
    private function renderHuman(array $about): void
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>dply</> '.$about['dply']['version']);
        $this->line('  Laravel '.$about['dply']['laravel'].' on PHP '.$about['dply']['php']);
        $this->line('  database: '.$about['dply']['database_driver'].'   environment: '.$about['dply']['environment']);

        $this->newLine();
        $this->line('<fg=cyan>Commands</>');
        $this->line('  dply:* total                     '.$about['commands']['dply_total']);

        $this->newLine();
        $this->line('<fg=cyan>Fleet</>');
        $this->line(sprintf('  %-32s %d', 'servers', $about['fleet']['servers']));
        $this->line(sprintf('  %-32s %d', 'sites', $about['fleet']['sites']));
        $this->line(sprintf('  %-32s %d', 'deployments_total', $about['fleet']['deployments_total']));
        $this->line(sprintf('  %-32s %d', 'database_engines_registered', $about['fleet']['database_engines_registered']));
        if (($about['fleet']['edge_sites'] ?? 0) > 0) {
            $this->line(sprintf(
                '  %-32s %d (source-mode: %d, previews: %d)',
                'edge_sites',
                $about['fleet']['edge_sites'],
                $about['fleet']['edge_source_mode_sites'] ?? 0,
                $about['fleet']['edge_preview_sites'] ?? 0,
            ));
        }
    }
}
