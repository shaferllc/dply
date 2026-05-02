<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Fleet-wide diagnostic — runs the same drift checks as dply:server:doctor
 * across every server, then summarizes the worst offenders.
 *
 *   dply:fleet:doctor [--ready] [--json]
 *
 * Output:
 *   - human: a short table of servers with drift counts (per-server,
 *     plus totals at the bottom). Servers with no drift are excluded
 *     from the table — the goal is to surface what needs attention,
 *     not list every healthy server.
 *   - --json: per-server drift report for the entire fleet, including
 *     servers with no drift (so scripts can confirm a fleet-wide
 *     "all green" state).
 */
class FleetDoctorCommand extends Command
{
    protected $signature = 'dply:fleet:doctor
        {--ready : Only check servers in STATUS_READY}
        {--json : Output as JSON}';

    protected $description = 'Fleet-wide drift report: runs server:doctor across every server and summarizes.';

    public function handle(): int
    {
        $query = Server::query()->with('databaseEngines')->orderBy('name');
        if ($this->option('ready')) {
            $query->where('status', Server::STATUS_READY);
        }
        $servers = $query->get();

        $reports = $servers->map(function (Server $server) {
            $engineKeys = $server->databaseEngines->pluck('engine')->all();
            $runtimeDefaultKeys = array_keys(is_array($server->meta['runtime_defaults'] ?? null)
                ? $server->meta['runtime_defaults']
                : []);

            $sitesWithMissingEngine = Site::query()
                ->where('server_id', $server->id)
                ->whereNotNull('database_engine')
                ->whereNotIn('database_engine', $engineKeys)
                ->count();

            $sitesNeedingRuntimeInstall = Site::query()
                ->where('server_id', $server->id)
                ->whereNotIn('runtime', array_merge($runtimeDefaultKeys, ['php', 'static']))
                ->whereNotNull('runtime')
                ->count();

            return [
                'server_id' => $server->id,
                'server_name' => $server->name,
                'sites_with_unregistered_engine' => $sitesWithMissingEngine,
                'sites_needing_runtime_install' => $sitesNeedingRuntimeInstall,
                'has_drift' => ($sitesWithMissingEngine + $sitesNeedingRuntimeInstall) > 0,
            ];
        });

        $totals = [
            'servers_checked' => $reports->count(),
            'servers_with_drift' => $reports->where('has_drift', true)->count(),
            'sites_with_unregistered_engine' => (int) $reports->sum('sites_with_unregistered_engine'),
            'sites_needing_runtime_install' => (int) $reports->sum('sites_needing_runtime_install'),
        ];

        if ($this->option('json')) {
            $this->line(json_encode([
                'totals' => $totals,
                'servers' => $reports->all(),
            ], JSON_PRETTY_PRINT));

            return $totals['servers_with_drift'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan>Fleet doctor</>');
        $this->line(sprintf(
            '  %d servers checked, %d with drift.',
            $totals['servers_checked'],
            $totals['servers_with_drift'],
        ));
        $this->newLine();

        $rows = $reports
            ->where('has_drift', true)
            ->map(fn (array $r) => [
                $r['server_name'],
                (string) $r['sites_with_unregistered_engine'],
                (string) $r['sites_needing_runtime_install'],
            ])
            ->all();

        if ($rows === []) {
            $this->info('  No drift detected across the fleet.');

            return self::SUCCESS;
        }

        $this->table(['server', 'unregistered engine', 'unpinned runtime'], array_values($rows));
        $this->newLine();
        $this->line('<fg=gray>Run </><fg=white>dply:server:doctor &lt;server&gt;</><fg=gray> for per-server details.</>');

        return $totals['servers_with_drift'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
