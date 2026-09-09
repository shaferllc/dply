<?php

declare(strict_types=1);

namespace App\Services\WorkerPools;

use App\Models\CloudDatabase;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Database\Services\TrustedSourceManager;
use Illuminate\Support\Facades\Log;

/**
 * Allow a fleet worker's public IP on the origin site's managed clusters so
 * queue workers in another region can reach DigitalOcean Redis/Postgres.
 */
class SiteWorkerFleetTrustedSources
{
    public function __construct(
        private readonly TrustedSourceManager $trustedSources,
    ) {}

    public function grantForMember(Site $origin, Server $member, ?User $actor = null): int
    {
        $ip = trim((string) $member->ip_address);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return 0;
        }

        $actor ??= $origin->user;
        if (! $actor instanceof User) {
            return 0;
        }

        $granted = 0;
        foreach ($this->clusters($origin) as $cluster) {
            if (! $this->trustedSources->supports($cluster)) {
                continue;
            }

            $already = $this->trustedSources->liveFor($cluster)
                ->contains(fn ($row): bool => (string) $row->ip_address === $ip);
            if ($already) {
                continue;
            }

            try {
                $this->trustedSources->allow($cluster, $ip, $actor, now()->addYears(10));
                $granted++;
            } catch (\Throwable $e) {
                Log::warning('worker-fleet: trusted source grant failed', [
                    'cluster_id' => $cluster->id,
                    'member_id' => $member->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $granted;
    }

    /**
     * @return list<CloudDatabase>
     */
    private function clusters(Site $origin): array
    {
        $origin->loadMissing('bindings');
        $found = [];

        foreach ($origin->bindings as $binding) {
            if ($binding->target_type !== 'cloud_database' || blank($binding->target_id)) {
                continue;
            }
            $cluster = CloudDatabase::query()->find($binding->target_id);
            if ($cluster instanceof CloudDatabase) {
                $found[$cluster->id] = $cluster;
            }
        }

        return array_values($found);
    }
}
