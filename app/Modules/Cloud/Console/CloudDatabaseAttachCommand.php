<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Console;

use App\Modules\Cloud\Jobs\AttachCloudDatabaseJob;
use App\Models\CloudDatabase;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Attach a managed database to a Cloud container site.
 *
 *   dply:cloud:db:attach <database> <site>
 *
 * Queues AttachCloudDatabaseJob, which merges the database's connection
 * env vars (DB_* / REDIS_*) into the site's env file, pushes them to
 * the backend, and redeploys.
 */
class CloudDatabaseAttachCommand extends Command
{
    protected $signature = 'dply:cloud:db:attach
        {database : Managed database ID or name}
        {site : Site ID, slug, or name}';

    protected $description = 'Attach a managed database to a Cloud container site.';

    public function handle(): int
    {
        $dbNeedle = (string) $this->argument('database');
        $database = $this->resolveDatabase($dbNeedle);
        if ($database === null) {
            $this->error("Managed database not found: {$dbNeedle}");

            return self::FAILURE;
        }

        if (! $database->isActive()) {
            $this->error(sprintf(
                'Database "%s" is %s — it must be active before attaching.',
                $database->name,
                $database->status,
            ));

            return self::FAILURE;
        }

        $siteNeedle = (string) $this->argument('site');
        $site = $this->resolveSite($siteNeedle);
        if ($site === null) {
            $this->error("Site not found: {$siteNeedle}");

            return self::FAILURE;
        }

        if ($site->container_backend === '') {
            $this->error("Site {$site->name} is not a cloud container site.");

            return self::FAILURE;
        }

        AttachCloudDatabaseJob::dispatch($database->id, $site->id, detach: false);
        $this->info(sprintf('Attach queued: database "%s" → site "%s".', $database->name, $site->name));

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
