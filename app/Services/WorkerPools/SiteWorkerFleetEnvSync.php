<?php

declare(strict_types=1);

namespace App\Services\WorkerPools;

use App\Models\Site;
use App\Services\Sites\SiteEnvPushScheduler;

/**
 * Copy the origin site's .env onto every hidden fleet replica (replica
 * transform applied) and queue an SSH push on each replica box.
 */
class SiteWorkerFleetEnvSync
{
    public function __construct(
        private readonly WorkerWorkloadReplayer $replayer,
        private readonly SiteEnvPushScheduler $pushes,
    ) {}

    public function syncFromOrigin(Site $origin): int
    {
        if ($origin->isFleetReplica()) {
            return 0;
        }

        $env = $this->replayer->replicaEnv((string) $origin->env_file_content);
        $synced = 0;

        foreach ($origin->fleetReplicaSites() as $replica) {
            $replica->forceFill(['env_file_content' => $env])->save();
            $this->pushes->schedule($replica, $origin->user_id);
            $synced++;
        }

        return $synced;
    }
}
