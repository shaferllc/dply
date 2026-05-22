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
 * Job to provision a dply Cloud Kubernetes cluster.
 *
 * This job runs asynchronously to create a DOKS cluster via the
 * DigitalOcean API and polls until the cluster is ready.
 */
class ProvisionClusterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 900; // 15 minutes - cluster provisioning can take 5-10 minutes

    public $tries = 1; // Don't retry - the job handles polling internally

    public function __construct(
        public CloudCluster $cluster,
    ) {}

    public function handle(ClusterProvisioner $provisioner): void
    {
        Log::info('Starting cluster provisioning job', [
            'cluster_id' => $this->cluster->id,
            'cluster_name' => $this->cluster->name,
            'region' => $this->cluster->region,
        ]);

        try {
            // Re-fetch the cluster in case state changed
            $this->cluster = CloudCluster::findOrFail($this->cluster->id);

            // Don't provision if already being handled
            if ($this->cluster->status === CloudCluster::STATUS_READY) {
                Log::info('Cluster already provisioned, skipping', [
                    'cluster_id' => $this->cluster->id,
                ]);

                return;
            }

            if ($this->cluster->status === CloudCluster::STATUS_ERROR) {
                Log::warning('Cluster is in error state, skipping provisioning', [
                    'cluster_id' => $this->cluster->id,
                    'error' => $this->cluster->error_message,
                ]);

                return;
            }

            // Run the provisioning process
            $provisioner->provision($this->cluster);

            Log::info('Cluster provisioning job completed successfully', [
                'cluster_id' => $this->cluster->id,
            ]);

        } catch (\Throwable $e) {
            Log::error('Cluster provisioning job failed', [
                'cluster_id' => $this->cluster->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update cluster status to error
            $this->cluster->update([
                'status' => CloudCluster::STATUS_ERROR,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Cluster provisioning job failed permanently', [
            'cluster_id' => $this->cluster->id,
            'error' => $exception->getMessage(),
        ]);

        $this->cluster->update([
            'status' => CloudCluster::STATUS_ERROR,
            'error_message' => 'Job failed: '.$exception->getMessage(),
        ]);
    }
}
