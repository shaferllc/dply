<?php

namespace App\Jobs\Cloud;

use App\Models\Cloud\CloudCluster;
use App\Services\Cloud\ClusterProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to sync cluster status from DigitalOcean API.
 *
 * This can be scheduled to run periodically to keep cluster status in sync.
 */
class SyncClusterStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public CloudCluster $cluster,
    ) {}

    public function handle(ClusterProvisioner $provisioner): void
    {
        Log::debug('Syncing cluster status', [
            'cluster_id' => $this->cluster->id,
        ]);

        try {
            // Re-fetch to get latest state
            $this->cluster = CloudCluster::findOrFail($this->cluster->id);

            $provisioner->syncClusterStatus($this->cluster);

        } catch (\Throwable $e) {
            Log::error('Cluster status sync failed', [
                'cluster_id' => $this->cluster->id,
                'error' => $e->getMessage(),
            ]);

            // Don't throw - this is a background sync job
        }
    }
}
