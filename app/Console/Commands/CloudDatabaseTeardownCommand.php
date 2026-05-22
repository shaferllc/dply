<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\TeardownCloudDatabaseJob;
use App\Models\CloudDatabase;
use Illuminate\Console\Command;

/**
 * Tear down a managed database.
 *
 *   dply:cloud:db:teardown <database>
 *
 * Queues TeardownCloudDatabaseJob, which deletes the DigitalOcean
 * Managed Database cluster and then the CloudDatabase row. Idempotent —
 * a cluster already gone on the provider side is treated as success.
 *
 * No undo. The data is destroyed with the cluster.
 */
class CloudDatabaseTeardownCommand extends Command
{
    protected $signature = 'dply:cloud:db:teardown
        {database : Managed database ID or name}';

    protected $description = 'Tear down a managed database (and its provider cluster).';

    public function handle(): int
    {
        $needle = (string) $this->argument('database');
        $database = $this->resolveDatabase($needle);
        if ($database === null) {
            $this->error("Managed database not found: {$needle}");

            return self::FAILURE;
        }

        TeardownCloudDatabaseJob::dispatch($database->id);
        $this->info(sprintf('Teardown queued for managed database "%s".', $database->name));

        return self::SUCCESS;
    }

    private function resolveDatabase(string $needle): ?CloudDatabase
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return CloudDatabase::query()
            ->where('id', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
