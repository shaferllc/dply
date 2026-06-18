<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\InstallLogAggregatorJob;
use App\Models\Server;
use App\Models\ServerLogAggregator;
use Illuminate\Console\Command;

/**
 * Stand up (or re-sync) the dply Logs Vector aggregator on a designated log box.
 * Creates the {@see ServerLogAggregator} row and dispatches {@see InstallLogAggregatorJob},
 * which generates the mTLS PKI on-box, configures Vector → ClickHouse, and captures
 * the edge mTLS material so edges auto-configure. Run dply:logs:schema-sync first so
 * the ClickHouse table exists. See docs/SERVER_LOGS_ADDON.md.
 *
 *   php artisan dply:logs:install-aggregator <server-id> [--port=6000] [--sync]
 */
class InstallLogAggregatorCommand extends Command
{
    protected $signature = 'dply:logs:install-aggregator
        {server : Server id (the box to run the aggregator on)}
        {--port=6000 : Port the aggregator listens on for the edge mTLS link}
        {--sync : Run the install inline instead of dispatching to the queue}';

    protected $description = 'Install/re-sync the dply Logs Vector aggregator on a server';

    public function handle(): int
    {
        $server = Server::query()->find($this->argument('server'));
        if ($server === null) {
            $this->error("Server [{$this->argument('server')}] not found.");

            return self::FAILURE;
        }

        if (! $server->isVmHost()) {
            $this->error('The aggregator can only run on a VM host server.');

            return self::FAILURE;
        }

        $port = max(1, (int) $this->option('port'));

        $aggregator = ServerLogAggregator::query()->updateOrCreate(
            ['server_id' => $server->id],
            ['status' => ServerLogAggregator::STATUS_INSTALLING, 'listen_port' => $port, 'install_output' => '', 'error_message' => null],
        );

        $this->info("Installing dply Logs aggregator on {$server->name} (:{$port}) …");

        if ($this->option('sync')) {
            dispatch_sync(new InstallLogAggregatorJob($aggregator->id));
            $fresh = $aggregator->fresh();
            $this->line("Status: {$fresh?->status}");
            if ($fresh?->status === ServerLogAggregator::STATUS_FAILED) {
                $this->error((string) $fresh->error_message);

                return self::FAILURE;
            }
            $this->info("Aggregator running. Edge endpoint: {$fresh?->endpoint}");

            return self::SUCCESS;
        }

        InstallLogAggregatorJob::dispatch($aggregator->id);
        $this->info('Install dispatched. Poll the server_log_aggregators row for status.');

        return self::SUCCESS;
    }
}
