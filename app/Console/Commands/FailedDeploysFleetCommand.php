<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Console\Command;

/**
 * List sites whose most recent deployment failed and hasn't been
 * retried successfully.
 *
 *   dply:fleet:failed-deploys
 *   dply:fleet:failed-deploys --include-running
 *   dply:fleet:failed-deploys --json
 *
 * Iterates each site's deployments newest-first and includes the
 * site in the result if the latest deploy is in STATUS_FAILED. By
 * default `running` deploys are skipped (we want the *settled*
 * latest); --include-running treats running deploys as the latest
 * for the rare "is this still running or is it stuck?" workflow.
 *
 * Reports site, server, runtime, deploy ID, trigger, finished_at.
 * Exit code 0 when nothing's failing, 1 when there's at least one
 * failed deploy — for CI dashboards that want red on failure.
 */
class FailedDeploysFleetCommand extends Command
{
    protected $signature = 'dply:fleet:failed-deploys
        {--include-running : Treat running deploys as the latest (default skips them)}
        {--json : Output as JSON}';

    protected $description = 'List sites whose most recent deployment failed.';

    public function handle(): int
    {
        $includeRunning = (bool) $this->option('include-running');

        $sites = Site::query()->get(['id', 'name', 'slug', 'server_id', 'runtime']);
        $servers = Server::query()
            ->whereIn('id', $sites->pluck('server_id')->filter()->unique())
            ->get(['id', 'name'])
            ->keyBy('id');

        $rows = [];
        foreach ($sites as $site) {
            $query = SiteDeployment::query()->where('site_id', $site->id);
            if (! $includeRunning) {
                $query->whereIn('status', [
                    SiteDeployment::STATUS_SUCCESS,
                    SiteDeployment::STATUS_FAILED,
                    SiteDeployment::STATUS_SKIPPED,
                ]);
            }
            $latest = $query
                ->orderByDesc('started_at')
                ->first(['id', 'status', 'trigger', 'started_at', 'finished_at']);

            if ($latest === null || $latest->status !== SiteDeployment::STATUS_FAILED) {
                continue;
            }

            $server = $servers->get($site->server_id);
            $rows[] = [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'site_slug' => $site->slug,
                'runtime' => $site->runtime,
                'server_id' => $server?->id,
                'server_name' => $server?->name,
                'deployment_id' => $latest->id,
                'trigger' => $latest->trigger,
                'started_at' => $latest->started_at?->toIso8601String(),
                'finished_at' => $latest->finished_at?->toIso8601String(),
            ];
        }

        // Most recently failed first — matches the "what blew up most
        // recently?" mental model.
        usort($rows, function ($a, $b) {
            return strcmp((string) $b['finished_at'], (string) $a['finished_at']);
        });

        if ($this->option('json')) {
            $this->line(json_encode([
                'include_running' => $includeRunning,
                'count' => count($rows),
                'sites' => $rows,
            ], JSON_PRETTY_PRINT));

            return $rows === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($rows === []) {
            $this->info('No sites have a failed latest deploy.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('%d site(s) with failed latest deploy:', count($rows)));
        $this->newLine();
        $this->table(
            ['site', 'server', 'runtime', 'when', 'trigger', 'deploy id'],
            array_map(fn (array $r) => [
                $r['site_name'],
                $r['server_name'] ?? '—',
                $r['runtime'],
                $r['finished_at'] ?? '—',
                $r['trigger'],
                $r['deployment_id'],
            ], $rows),
        );
        $this->newLine();
        $this->line('<fg=gray>Drill in with: dply:site:show-deploy &lt;deployment-id&gt; --output</>');

        return self::FAILURE;
    }
}
