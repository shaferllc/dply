<?php

declare(strict_types=1);

namespace App\Modules\Logs\Console;

use App\Models\ServerLogAggregator;
use Illuminate\Console\Command;

/**
 * Report the dply Logs aggregators and whether each runs the current rendered
 * config ({@see \App\Support\Servers\VectorLogAggregatorInstallScripts::CONFIG_VERSION}).
 * Boxes on an older config are flagged with the exact re-sync command — this is how
 * operators learn a config change shipped and that they need to re-sync to apply it.
 *
 *   php artisan dply:logs:aggregator-status
 */
class AggregatorStatusCommand extends Command
{
    protected $signature = 'dply:logs:aggregator-status';

    protected $description = 'Show dply Logs aggregators and flag any running a stale config';

    public function handle(): int
    {
        $current = ServerLogAggregator::currentConfigVersion();
        $aggregators = ServerLogAggregator::query()->with('server')->get();

        if ($aggregators->isEmpty()) {
            $this->info('No dply Logs aggregators installed.');

            return self::SUCCESS;
        }

        $this->line("Current rendered config version: <info>v{$current}</info>");
        $this->newLine();

        $stale = [];
        $rows = $aggregators->map(function (ServerLogAggregator $a) use ($current, &$stale): array {
            $installed = $a->installedConfigVersion();
            $isStale = $a->isConfigStale();
            if ($isStale) {
                $stale[] = $a;
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

        if ($stale === []) {
            $this->info('All running aggregators are on the current config.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn(count($stale).' aggregator(s) are running a stale config. Re-sync to apply the latest:');
        foreach ($stale as $a) {
            $this->line("  php artisan dply:logs:install-aggregator {$a->server_id} --port={$a->listen_port} --sync");
        }

        return self::SUCCESS;
    }
}
