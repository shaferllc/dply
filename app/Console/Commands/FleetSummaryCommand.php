<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Fleet stats — counts of servers, sites by runtime, engines in use.
 *
 *   dply:fleet:summary [--json]
 *
 * Useful for "where are we" health checks and quarterly reviews:
 * how many sites are PHP vs Node vs Python? Which DB engines are
 * actually running? What proportion of servers are still in
 * STATUS_PENDING?
 */
class FleetSummaryCommand extends Command
{
    protected $signature = 'dply:fleet:summary
        {--json : Output as JSON}';

    protected $description = 'Fleet stats — server count, site count by runtime, engines in use.';

    public function handle(): int
    {
        $serverStatuses = Server::query()->select('status')->get()->groupBy('status')->map->count();
        $siteRuntimes = Site::query()
            ->select('runtime')
            ->get()
            ->groupBy(fn (Site $s) => $s->runtime ?? 'unset')
            ->map->count()
            ->sortKeys();
        $engineUsage = ServerDatabaseEngine::query()
            ->select('engine')
            ->get()
            ->groupBy('engine')
            ->map->count()
            ->sortKeys();
        $totalServers = Server::query()->count();
        $totalSites = Site::query()->count();

        $payload = [
            'totals' => [
                'servers' => $totalServers,
                'sites' => $totalSites,
            ],
            'server_statuses' => $serverStatuses->toArray(),
            'site_runtimes' => $siteRuntimes->toArray(),
            'engine_usage' => $engineUsage->toArray(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan>Fleet summary</>');
        $this->line(sprintf(
            '  %d servers, %d sites',
            $payload['totals']['servers'],
            $payload['totals']['sites'],
        ));
        $this->newLine();

        if ($serverStatuses->isNotEmpty()) {
            $this->line('<fg=cyan>Servers by status</>');
            $this->table(['status', 'count'], $serverStatuses->map(
                fn ($count, $status) => [$status, (string) $count]
            )->values()->all());
            $this->newLine();
        }

        if ($siteRuntimes->isNotEmpty()) {
            $this->line('<fg=cyan>Sites by runtime</>');
            $this->table(['runtime', 'count'], $siteRuntimes->map(
                fn ($count, $runtime) => [$runtime, (string) $count]
            )->values()->all());
            $this->newLine();
        }

        if ($engineUsage->isNotEmpty()) {
            $this->line('<fg=cyan>Database engines registered</>');
            $this->table(['engine', 'servers'], $engineUsage->map(
                fn ($count, $engine) => [$engine, (string) $count]
            )->values()->all());
        } else {
            $this->line('<fg=gray>No database engines registered yet.</>');
        }

        return self::SUCCESS;
    }
}
