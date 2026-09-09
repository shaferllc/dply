<?php

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\Site;

/**
 * How a worker host should present in the server workspace — role copy,
 * origin-site link, and whether this box is managed from a site’s
 * Worker Servers page (not as a standalone app host).
 */
final readonly class WorkerHostContext
{
    public function __construct(
        public bool $isWorkerHost,
        public bool $isSiteSourced,
        public ?Site $originSite,
        public ?string $manageUrl,
    ) {}

    public static function none(): self
    {
        return new self(false, false, null, null);
    }

    public static function for(Server $server): self
    {
        if (! $server->isWorkerHost()) {
            return self::none();
        }

        $isSiteSourced = $server->isSiteSourcedFleet();
        $origin = null;
        $manageUrl = null;

        if (filled($server->worker_pool_id)) {
            $pool = $server->relationLoaded('workerPool')
                ? $server->workerPool
                : $server->workerPool()->first();

            if ($pool !== null) {
                $origin = $pool->originSite();
                $manageUrl = $pool->workspaceUrl();
                $isSiteSourced = $isSiteSourced || $pool->isSiteSourced();
            }
        }

        if ($origin === null) {
            $replica = self::fleetReplicaOn($server);
            $originId = $replica !== null
                ? (string) data_get($replica->meta, 'fleet_replica_of_site_id')
                : '';

            if ($originId !== '') {
                $origin = Site::query()->find($originId);
                $isSiteSourced = true;
            }

            if ($origin instanceof Site && filled($origin->server_id) && $manageUrl === null) {
                $manageUrl = route('sites.show', [
                    'server' => $origin->server_id,
                    'site' => $origin,
                    'section' => 'worker-fleet',
                ]);
            }
        }

        return new self(true, $isSiteSourced, $origin, $manageUrl);
    }

    private static function fleetReplicaOn(Server $server): ?Site
    {
        $loaded = $server->relationLoaded('sites') ? $server->sites : null;
        if ($loaded !== null) {
            return $loaded->first(fn (Site $site): bool => $site->isFleetReplica());
        }

        return Site::query()
            ->where('server_id', $server->id)
            ->whereRaw("coalesce(meta->>'fleet_replica_of_site_id', '') != ''")
            ->first();
    }
}
