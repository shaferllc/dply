<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProviderCredential;
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

        $flyConnected = ProviderCredential::query()->where('provider', 'fly_io')->exists();
        $edgeEligibleSites = Site::query()
            ->whereIn('runtime', ['node', 'static'])
            ->count();

        $edgeSites = Site::query()
            ->whereNotNull('container_backend')
            ->get(['container_backend', 'status']);
        $edgeByBackend = $edgeSites->groupBy('container_backend')->map->count()->sortKeys()->all();
        $edgeByStatus = $edgeSites->groupBy('status')->map->count()->sortKeys()->all();
        $edgeBackendCredentials = ProviderCredential::query()
            ->whereIn('provider', ['digitalocean_app_platform', 'aws_app_runner'])
            ->get(['provider'])
            ->groupBy('provider')
            ->map->count()
            ->all();

        $payload = [
            'totals' => [
                'servers' => $totalServers,
                'sites' => $totalSites,
            ],
            'server_statuses' => $serverStatuses->toArray(),
            'site_runtimes' => $siteRuntimes->toArray(),
            'engine_usage' => $engineUsage->toArray(),
            'fly_io' => [
                'connected' => $flyConnected,
                'edge_eligible_sites' => $edgeEligibleSites,
            ],
            'edge_fleet' => [
                'total' => $edgeSites->count(),
                'by_backend' => $edgeByBackend,
                'by_status' => $edgeByStatus,
                'backend_credentials' => $edgeBackendCredentials,
            ],
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

        if (! $flyConnected && $edgeEligibleSites > 0) {
            $this->newLine();
            $this->line(sprintf(
                '<fg=cyan>Fly.io edge:</> <fg=gray>not connected.</> %d %s could deploy at the edge.',
                $edgeEligibleSites,
                trans_choice('{1} Node/static site|[2,*] Node/static sites', $edgeEligibleSites),
            ));
            $this->line('<fg=gray>  Connect: dply:list-engines | docs: https://fly.io/docs/about/pricing</>');
        }

        if ($edgeSites->isNotEmpty()) {
            $this->newLine();
            $this->line('<fg=cyan>Dply edge</>');
            $this->line(sprintf('  %d edge container site(s)', $edgeSites->count()));
            if ($edgeByBackend !== []) {
                $this->table(['backend', 'count'], array_map(
                    fn ($backend) => [$backend, (string) $edgeByBackend[$backend]],
                    array_keys($edgeByBackend),
                ));
            }
            $statusOrder = [
                Site::STATUS_CONTAINER_ACTIVE,
                Site::STATUS_CONTAINER_PROVISIONING,
                Site::STATUS_CONTAINER_FAILED,
            ];
            $statusRows = [];
            foreach ($statusOrder as $status) {
                if (isset($edgeByStatus[$status])) {
                    $statusRows[] = [$status, (string) $edgeByStatus[$status]];
                }
            }
            if ($statusRows !== []) {
                $this->table(['status', 'count'], $statusRows);
            }
        }

        return self::SUCCESS;
    }
}
