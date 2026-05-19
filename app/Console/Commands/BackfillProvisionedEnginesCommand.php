<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Servers\SeedProvisionedEnginesForServer;
use App\Models\Server;
use Illuminate\Console\Command;

/**
 * Retroactively seed `server_cache_services` / `server_database_engines`
 * rows from each ready server's provision meta. Servers provisioned before
 * the SeedProvisionedEnginesForServer hook landed have the engines running
 * on the host but no rows for the Caches / Databases workspace pages to
 * render; this command fixes that in-place.
 *
 * Idempotent: {@see SeedProvisionedEnginesForServer} only inserts when the
 * matching (server_id, engine) row is missing, so re-running is a no-op.
 *
 * Examples:
 *   php artisan dply:servers:backfill-engine-rows --dry-run
 *   php artisan dply:servers:backfill-engine-rows --server=01krykzwqvqpncstk1ev63j4dv
 */
class BackfillProvisionedEnginesCommand extends Command
{
    protected $signature = 'dply:servers:backfill-engine-rows
                            {--server= : Limit to one server (ULID)}
                            {--dry-run : Print what would be inserted without writing}';

    protected $description = 'Seed server_cache_services and server_database_engines rows from server meta for servers that pre-date the provision-time seeder.';

    public function handle(SeedProvisionedEnginesForServer $seeder): int
    {
        $serverFilter = $this->option('server');
        $dryRun = (bool) $this->option('dry-run');

        $query = Server::query()
            ->where('setup_status', Server::SETUP_STATUS_DONE)
            ->orderBy('created_at');

        if (is_string($serverFilter) && $serverFilter !== '') {
            $query->whereKey($serverFilter);
        }

        $servers = $query->get();

        if ($servers->isEmpty()) {
            $this->info('No matching servers.');

            return self::SUCCESS;
        }

        $cacheTotal = 0;
        $dbTotal = 0;
        $touched = 0;

        foreach ($servers as $server) {
            $meta = $server->meta ?? [];
            $cache = (string) ($meta['cache_service'] ?? '');
            $db = (string) ($meta['database'] ?? '');

            if ($dryRun) {
                $cacheLabel = $cache === '' || $cache === 'none' ? '—' : $cache;
                $dbLabel = $db === '' || $db === 'none' ? '—' : $db;
                $this->line(sprintf(
                    '[dry-run] %s  cache=%s  database=%s',
                    $server->id,
                    $cacheLabel,
                    $dbLabel,
                ));

                continue;
            }

            $result = $seeder->execute($server);

            if ($result['cache_created']) {
                $cacheTotal++;
            }
            if ($result['database_created']) {
                $dbTotal++;
            }
            if ($result['cache_created'] || $result['database_created']) {
                $touched++;
                $this->line(sprintf(
                    '  %s  cache=%s  database=%s',
                    $server->id,
                    $result['cache_created'] ? '+' : '·',
                    $result['database_created'] ? '+' : '·',
                ));
            }
        }

        if ($dryRun) {
            $this->info(sprintf('Dry-run: scanned %d server(s). No changes written.', $servers->count()));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Done. %d server(s) updated — %d cache row(s), %d database engine row(s) inserted.',
            $touched,
            $cacheTotal,
            $dbTotal,
        ));

        return self::SUCCESS;
    }
}
