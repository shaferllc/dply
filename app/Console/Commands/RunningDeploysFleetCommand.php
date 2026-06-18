<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Console\Command;

/**
 * List every currently-in-progress deployment in the fleet.
 *
 *   dply:fleet:running-deploys
 *   dply:fleet:running-deploys --json
 *   dply:fleet:running-deploys --older-than=15
 *
 * Reports site, server, deploy ID, trigger, started_at, age. Sorted
 * by start time so the longest-running surface at the top —
 * operationally useful when you suspect a stuck deploy.
 *
 * --older-than=N filters to deploys older than N minutes (the
 * threshold dply:site:abort-deploy uses), so this becomes "show me
 * deploys that have probably hung".
 */
class RunningDeploysFleetCommand extends Command
{
    protected $signature = 'dply:fleet:running-deploys
        {--older-than= : Filter to deploys older than N minutes}
        {--json : Output as JSON}';

    protected $description = 'List every currently-in-progress deployment in the fleet.';

    public function handle(): int
    {
        $minMinutes = $this->option('older-than') !== null
            ? max(0, (int) $this->option('older-than'))
            : null;

        $running = SiteDeployment::query()
            ->where('status', SiteDeployment::STATUS_RUNNING)
            ->orderBy('started_at')
            ->get(['id', 'site_id', 'trigger', 'started_at']);

        $sites = Site::query()
            ->whereIn('id', $running->pluck('site_id')->unique())
            ->get(['id', 'name', 'slug', 'server_id', 'runtime'])
            ->keyBy('id');
        $servers = Server::query()
            ->whereIn('id', $sites->pluck('server_id')->filter()->unique())
            ->get(['id', 'name'])
            ->keyBy('id');

        $rows = [];
        foreach ($running as $d) {
            $site = $sites->get($d->site_id);
            if ($site === null) {
                continue;
            }
            $ageMinutes = $d->started_at !== null
                ? (int) round($d->started_at->diffInMinutes(now()))
                : null;
            if ($minMinutes !== null && ($ageMinutes === null || $ageMinutes < $minMinutes)) {
                continue;
            }
            $server = $site->server_id ? $servers->get($site->server_id) : null;
            $rows[] = [
                'deployment_id' => $d->id,
                'site_id' => $site->id,
                'site_name' => $site->name,
                'site_slug' => $site->slug,
                'runtime' => $site->runtime,
                'server_id' => $server?->id,
                'server_name' => $server?->name,
                'trigger' => $d->trigger,
                'started_at' => $d->started_at?->toIso8601String(),
                'age_minutes' => $ageMinutes,
            ];
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'older_than_minutes' => $minMinutes,
                'count' => count($rows),
                'deployments' => $rows,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info($minMinutes !== null
                ? sprintf('No running deploys older than %d minute(s).', $minMinutes)
                : 'No deploys are currently running.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d running deploy(s)%s:',
            count($rows),
            $minMinutes !== null ? ' older than '.$minMinutes.'m' : ''));
        $this->newLine();
        $this->table(
            ['site', 'server', 'runtime', 'trigger', 'age', 'deploy id'],
            array_map(fn (array $r) => [
                $r['site_name'],
                $r['server_name'] ?? '—',
                $r['runtime'],
                $r['trigger'],
                $r['age_minutes'] !== null ? $r['age_minutes'].'m' : '—',
                $r['deployment_id'],
            ], $rows),
        );

        return self::SUCCESS;
    }
}
