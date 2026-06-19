<?php

declare(strict_types=1);

namespace App\Modules\Logs\Console;

use App\Models\ServerLogAgent;
use Illuminate\Console\Command;

/**
 * Fleet view of the dply Logs EDGE agents and whether each runs the current rendered
 * config ({@see \App\Support\Servers\VectorLogAgentInstallScripts::CONFIG_VERSION}).
 * Read-only: edges are re-synced per server from the Logs → Shipping tab ("Re-sync
 * agent"), which is idempotent. This command is the fleet-wide "which boxes are stale"
 * lens you can't get by eyeballing every server's UI.
 *
 *   php artisan dply:logs:agent-status
 */
class AgentStatusCommand extends Command
{
    protected $signature = 'dply:logs:agent-status';

    protected $description = 'Show dply Logs edge agents and flag any running a stale config';

    public function handle(): int
    {
        $current = ServerLogAgent::currentConfigVersion();
        $agents = ServerLogAgent::query()->with('server')->get();

        if ($agents->isEmpty()) {
            $this->info('No dply Logs edge agents installed.');

            return self::SUCCESS;
        }

        $this->line("Current rendered config version: <info>v{$current}</info>");
        $this->newLine();

        $staleCount = 0;
        $rows = $agents->map(function (ServerLogAgent $a) use ($current, &$staleCount): array {
            $installed = $a->installedConfigVersion();
            $isStale = $a->isConfigStale();
            if ($isStale) {
                $staleCount++;
            }

            return [
                $a->server?->name ?? $a->server_id,
                $a->status,
                $installed === null ? '—' : "v{$installed}",
                "v{$current}",
                $isStale ? '⚠ update available' : ($a->isRunning() ? 'up to date' : ''),
            ];
        })->all();

        $this->table(['Server', 'Status', 'Installed', 'Current', ''], $rows);

        if ($staleCount === 0) {
            $this->info('All running edge agents are on the current config.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn("{$staleCount} edge agent(s) are running a stale config.");
        $this->line('Re-sync each from its server → Logs → Shipping tab ("Re-sync agent"). It is idempotent.');

        return self::SUCCESS;
    }
}
