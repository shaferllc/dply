<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AttachCloudDatabaseJob;
use App\Models\CloudDatabase;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Detach a managed database from a Cloud container site.
 *
 *   dply:cloud:db:detach <database> <site>
 *
 * Queues AttachCloudDatabaseJob in detach mode, which strips the
 * database's connection env vars (DB_* / REDIS_*) from the site's env
 * file, pushes the change to the backend, and redeploys.
 */
class CloudDatabaseDetachCommand extends Command
{
    protected $signature = 'dply:cloud:db:detach
        {database : Managed database ID or name}
        {site : Site ID, slug, or name}';

    protected $description = 'Detach a managed database from a Cloud container site.';

    public function handle(): int
    {
        $dbNeedle = (string) $this->argument('database');
        $database = $this->resolveDatabase($dbNeedle);
        if ($database === null) {
            $this->error("Managed database not found: {$dbNeedle}");

            return self::FAILURE;
        }

        $siteNeedle = (string) $this->argument('site');
        $site = $this->resolveSite($siteNeedle);
        if ($site === null) {
            $this->error("Site not found: {$siteNeedle}");

            return self::FAILURE;
        }

        AttachCloudDatabaseJob::dispatch($database->id, $site->id, detach: true);
        $this->info(sprintf('Detach queued: database "%s" ✕ site "%s".', $database->name, $site->name));

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

    private function resolveSite(string $needle): ?Site
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Site::query()
            ->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
    }
}
