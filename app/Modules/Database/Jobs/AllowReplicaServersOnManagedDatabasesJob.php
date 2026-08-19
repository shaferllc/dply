<?php

declare(strict_types=1);

namespace App\Modules\Database\Jobs;

use App\Models\CloudDatabase;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Database\Services\TrustedSourceManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Allowlists a primary's worker-replica servers on every managed database it binds.
 *
 * Network lockdown (see DoManagedBackend::lockNetworkTo) sets a cluster's trusted
 * sources to the ONE server present at provision time. A worker-pool replica runs
 * the same application and needs the same database and Redis access, but was never
 * added — so it could hold the primary's credentials and still connect-timeout,
 * which is what "Redis down" on a healthy worker actually meant.
 *
 * Additive and idempotent: the manager reads the current rules and appends only
 * what is missing, so the primary's rule and anything added by hand survive.
 */
class AllowReplicaServersOnManagedDatabasesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A provider API failure should not re-fan-out the whole set. */
    public int $maxExceptions = 1;

    public int $uniqueFor = 600;

    public function __construct(public string $primarySiteId) {}

    public function uniqueId(): string
    {
        return 'managed-db-trusted-sources:'.$this->primarySiteId;
    }

    public function handle(TrustedSourceManager $manager): void
    {
        if (! $manager->writesEnabled()) {
            return;
        }

        $primary = Site::query()->find($this->primarySiteId);
        if (! $primary instanceof Site) {
            return;
        }

        $servers = $this->replicaServers($primary);
        if ($servers === []) {
            return;
        }

        foreach ($this->managedDatabases($primary) as $database) {
            foreach ($servers as $server) {
                if ($manager->allowServer($database, $server)) {
                    Log::info('database.trusted_sources.replica_allowed', [
                        'cloud_database_id' => (string) $database->id,
                        'server_id' => (string) $server->id,
                        'primary_site_id' => $this->primarySiteId,
                    ]);
                }
            }
        }
    }

    /**
     * Every managed cluster this site binds — database AND redis, since a worker
     * needs the queue backend just as much as the database.
     *
     * @return list<CloudDatabase>
     */
    private function managedDatabases(Site $primary): array
    {
        $ids = SiteBinding::query()
            ->where('site_id', $primary->id)
            ->where('target_type', 'cloud_database')
            ->whereNotNull('target_id')
            ->pluck('target_id')
            ->unique()
            ->all();

        if ($ids === []) {
            return [];
        }

        return CloudDatabase::query()
            ->whereIn('id', $ids)
            ->whereNotNull('backend_id')
            ->get()
            ->all();
    }

    /**
     * Distinct servers hosting this primary's replicas. The primary's own server
     * is already trusted by the provisioner.
     *
     * @return list<Server>
     */
    private function replicaServers(Site $primary): array
    {
        $serverIds = Site::query()
            ->where('meta->replicated_from_site_id', (string) $primary->id)
            ->whereNotNull('server_id')
            ->pluck('server_id')
            ->reject(fn ($id): bool => (string) $id === (string) $primary->server_id)
            ->unique()
            ->all();

        if ($serverIds === []) {
            return [];
        }

        return Server::query()->whereIn('id', $serverIds)->get()->all();
    }
}
