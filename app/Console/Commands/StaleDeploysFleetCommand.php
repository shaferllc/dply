<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Console\Command;

/**
 * List sites whose latest successful deploy is older than N days.
 *
 *   dply:fleet:stale-deploys                  # default 30 days
 *   dply:fleet:stale-deploys --days=90
 *   dply:fleet:stale-deploys --include-never  # also include never-deployed sites
 *   dply:fleet:stale-deploys --json
 *
 * Useful for:
 *   - "Which sites are stagnant?" audits before a deprecation sweep
 *   - Security reviews (sites with old code haven't picked up patches)
 *   - Inventory of forgotten projects
 *
 * Reports site name, server, runtime, last deploy timestamp + age,
 * and last deploy status. Sorted oldest-first so the most stale
 * surface at the top.
 */
class StaleDeploysFleetCommand extends Command
{
    protected $signature = 'dply:fleet:stale-deploys
        {--days=30 : Threshold in days; deploys older than this are stale}
        {--include-never : Also include sites that have never deployed}
        {--json : Output as JSON}';

    protected $description = 'List sites whose latest deploy is older than N days.';

    public function handle(): int
    {
        $days = max(0, (int) ($this->option('days') ?? 30));
        $includeNever = (bool) $this->option('include-never');
        $threshold = now()->subDays($days);

        $sites = Site::query()->get(['id', 'name', 'slug', 'server_id', 'runtime']);
        $servers = Server::query()
            ->whereIn('id', $sites->pluck('server_id')->filter()->unique())
            ->get(['id', 'name'])
            ->keyBy('id');

        $rows = [];
        foreach ($sites as $site) {
            $latest = SiteDeployment::query()
                ->where('site_id', $site->id)
                ->where('status', SiteDeployment::STATUS_SUCCESS)
                ->orderByDesc('finished_at')
                ->first(['id', 'status', 'finished_at']);

            if ($latest === null) {
                if (! $includeNever) {
                    continue;
                }
                $rows[] = $this->row($site, $servers->get($site->server_id), null, null, null);

                continue;
            }

            if ($latest->finished_at === null || $latest->finished_at->isAfter($threshold)) {
                continue;
            }

            $rows[] = $this->row(
                $site,
                $servers->get($site->server_id),
                $latest->id,
                $latest->finished_at->toIso8601String(),
                (int) round($latest->finished_at->diffInDays(now())),
            );
        }

        // Oldest-first: never-deployed (null age) sort to bottom or top?
        // Convention: never-deployed go to the BOTTOM since "infinite age"
        // is technically the oldest but operators want concrete dates first.
        usort($rows, function ($a, $b) {
            $ageA = $a['age_days'] ?? -1;
            $ageB = $b['age_days'] ?? -1;
            if ($ageA === -1 && $ageB === -1) {
                return 0;
            }
            if ($ageA === -1) {
                return 1;
            }
            if ($ageB === -1) {
                return -1;
            }

            return $ageB - $ageA;
        });

        if ($this->option('json')) {
            $this->line(json_encode([
                'days' => $days,
                'include_never' => $includeNever,
                'count' => count($rows),
                'sites' => $rows,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info(sprintf(
                'No stale sites — every site has a successful deploy within the last %d day(s)%s.',
                $days,
                $includeNever ? ' (including never-deployed sites)' : '',
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf('%d site(s) with stale deploys (threshold: %d days):', count($rows), $days));
        $this->newLine();
        $this->table(
            ['site', 'server', 'runtime', 'last deploy', 'age'],
            array_map(fn (array $r) => [
                $r['site_name'],
                $r['server_name'] ?? '—',
                $r['runtime'] ?? '—',
                $r['last_deploy_at'] ?? '<fg=yellow>never</>',
                $r['age_days'] !== null ? $r['age_days'].'d' : '—',
            ], $rows),
        );

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Site $site, ?Server $server, ?string $deploymentId, ?string $finishedAt, ?int $ageDays): array
    {
        return [
            'site_id' => $site->id,
            'site_name' => $site->name,
            'site_slug' => $site->slug,
            'runtime' => $site->runtime,
            'server_id' => $server?->id,
            'server_name' => $server?->name,
            'latest_deployment_id' => $deploymentId,
            'last_deploy_at' => $finishedAt,
            'age_days' => $ageDays,
        ];
    }
}
