<?php

declare(strict_types=1);

namespace App\Modules\Logs;

use Illuminate\Support\ServiceProvider;

/** Logs module command wiring — server-logs store/aggregator/usage CLI (2 scheduled). */
class LogsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\SyncLogStoreSchemaCommand::class,
                Console\SyncLogAggregatorPolicyCommand::class,
                Console\MeterServerLogUsageCommand::class,
                Console\InstallLogAggregatorCommand::class,
                Console\LogDrainListen::class,
                Console\PruneAppLogsCommand::class,
            ]);
        }
    }
}
