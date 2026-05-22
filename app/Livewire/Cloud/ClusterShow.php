<?php

namespace App\Livewire\Cloud;

use App\Models\Cloud\CloudCluster;
use App\Services\Cloud\ClusterProvisioner;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Livewire component for viewing a dply Cloud cluster.
 */
class ClusterShow extends Component
{
    public CloudCluster $cluster;

    public bool $confirmingDelete = false;
    public bool $isDeleting = false;

    public function mount(CloudCluster $cluster): void
    {
        $this->cluster = $cluster;
    }

    /**
     * Get cluster status with human-readable labels.
     */
    #[Computed]
    public function statusDisplay(): array
    {
        return match ($this->cluster->status) {
            CloudCluster::STATUS_PENDING => [
                'label' => 'Pending',
                'color' => 'yellow',
                'icon' => 'clock',
            ],
            CloudCluster::STATUS_PROVISIONING => [
                'label' => 'Provisioning',
                'color' => 'blue',
                'icon' => 'cog',
            ],
            CloudCluster::STATUS_READY => [
                'label' => 'Ready',
                'color' => 'green',
                'icon' => 'check-circle',
            ],
            CloudCluster::STATUS_ERROR => [
                'label' => 'Error',
                'color' => 'red',
                'icon' => 'exclamation-circle',
            ],
            CloudCluster::STATUS_DELETING => [
                'label' => 'Deleting',
                'color' => 'yellow',
                'icon' => 'trash',
            ],
            default => [
                'label' => 'Unknown',
                'color' => 'gray',
                'icon' => 'question-mark-circle',
            ],
        };
    }

    /**
     * Get cluster region name.
     */
    #[Computed]
    public function regionName(): string
    {
        $regions = CloudCluster::availableRegions();

        return $regions[$this->cluster->region]['name'] ?? $this->cluster->region;
    }

    /**
     * Get node pool spec details.
     */
    #[Computed]
    public function nodePoolDetails(): array
    {
        $spec = $this->cluster->node_pool_spec ?? CloudCluster::defaultNodePoolSpec($this->cluster->tier);

        return [
            'size' => $spec['size'] ?? 'unknown',
            'count' => $spec['count'] ?? 1,
            'autoscale' => $spec['autoscale'] ?? false,
            'minNodes' => $spec['min_nodes'] ?? $spec['count'] ?? 1,
            'maxNodes' => $spec['max_nodes'] ?? $spec['count'] ?? 1,
        ];
    }

    /**
     * Get provisioned metadata.
     */
    #[Computed]
    public function provisionDetails(): array
    {
        $meta = $this->cluster->meta ?? [];

        return [
            'apiEndpoint' => $this->cluster->apiEndpoint(),
            'ingressEndpoint' => $this->cluster->ingressEndpoint(),
            'doClusterName' => $meta['do_cluster_name'] ?? null,
            'doClusterVersion' => $meta['do_cluster_version'] ?? null,
            'provisionStarted' => $meta['provision_started_at'] ?? null,
            'provisionCompleted' => $meta['provision_completed_at'] ?? null,
            'componentsInstalled' => $meta['components_installed'] ?? false,
        ];
    }

    /**
     * Get apps in this cluster.
     */
    #[Computed]
    public function apps(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->cluster->cloudApps()
            ->withCount('cloudDeploys')
            ->orderByDesc('created_at')
            ->paginate(10);
    }

    /**
     * Refresh cluster status.
     */
    public function refreshStatus(ClusterProvisioner $provisioner): void
    {
        try {
            $provisioner->syncClusterStatus($this->cluster);

            $this->dispatch('status-refreshed');

            $this->cluster = CloudCluster::findOrFail($this->cluster->id);

        } catch (\Throwable $e) {
            Log::error('Failed to refresh cluster status', [
                'cluster_id' => $this->cluster->id,
                'error' => $e->getMessage(),
            ]);

            session()->flash('error', 'Failed to refresh status: '.$e->getMessage());
        }
    }

    /**
     * Delete the cluster.
     */
    public function deleteCluster(ClusterProvisioner $provisioner): void
    {
        $this->isDeleting = true;

        try {
            $provisioner->deleteCluster($this->cluster);

            session()->flash('success', "Cluster '{$this->cluster->name}' is being deleted.");

            $this->redirect(route('cloud.clusters.index'), navigate: true);

        } catch (\Throwable $e) {
            Log::error('Failed to delete cluster', [
                'cluster_id' => $this->cluster->id,
                'error' => $e->getMessage(),
            ]);

            $this->isDeleting = false;
            $this->confirmingDelete = false;

            session()->flash('error', 'Failed to delete cluster: '.$e->getMessage());
        }
    }

    /**
     * Get SSH connection info for kubeconfig.
     */
    public function downloadKubeconfig(): void
    {
        $kubeconfig = $this->cluster->kubeconfigString();

        if (!$kubeconfig) {
            session()->flash('error', 'Kubeconfig not available yet.');

            return;
        }

        $this->dispatch('download-kubeconfig', [
            'filename' => "{$this->cluster->slug}-kubeconfig.yaml",
            'content' => $kubeconfig,
        ]);
    }

    /**
     * Auto-refresh when cluster is provisioning.
     */
    #[On('echo-private:cluster.{cluster.id},ClusterStatusUpdated')]
    public function onClusterStatusUpdated(): void
    {
        $this->cluster = CloudCluster::findOrFail($this->cluster->id);
    }

    public function render()
    {
        return view('livewire.cloud.cluster-show');
    }
}
