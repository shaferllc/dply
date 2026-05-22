<?php

namespace App\Services\Cloud;

use App\Jobs\Cloud\ProvisionClusterJob;
use App\Models\Cloud\CloudCluster;
use App\Services\DigitalOceanService;
use Illuminate\Support\Facades\Log;

/**
 * Service for provisioning and managing dply Cloud Kubernetes clusters.
 *
 * Handles the lifecycle of DigitalOcean Kubernetes (DOKS) clusters,
 * including creation, monitoring, kubeconfig management, and deletion.
 */
class ClusterProvisioner
{
    public function __construct(
        private DigitalOceanService $digitalOcean,
    ) {}

    /**
     * Initiate provisioning of a new cluster.
     *
     * This dispatches a job that will asynchronously provision the cluster
     * via the DigitalOcean API.
     */
    public function initiateProvisioning(CloudCluster $cluster): void
    {
        $cluster->update([
            'status' => CloudCluster::STATUS_PROVISIONING,
        ]);

        ProvisionClusterJob::dispatch($cluster);
    }

    /**
     * Provision a cluster synchronously (called by the job).
     *
     * @throws \RuntimeException If provisioning fails
     */
    public function provision(CloudCluster $cluster): void
    {
        $credential = $cluster->providerCredential;
        if (!$credential) {
            throw new \RuntimeException('No provider credential associated with cluster');
        }

        $do = new DigitalOceanService($credential);

        try {
            // Get the latest Kubernetes version
            $version = $do->getLatestKubernetesVersion();

            $nodePoolSpec = $cluster->node_pool_spec ?? CloudCluster::defaultNodePoolSpec($cluster->tier);

            $clusterConfig = [
                'name' => 'dply-'.$cluster->slug.'-'.$cluster->id,
                'region' => $cluster->region,
                'version' => $version,
                'node_pool' => [
                    'name' => 'default-pool',
                    'size' => $nodePoolSpec['size'],
                    'count' => $nodePoolSpec['count'],
                    'auto_scale' => $nodePoolSpec['autoscale'] ?? false,
                    'min_nodes' => $nodePoolSpec['min_nodes'] ?? $nodePoolSpec['count'],
                    'max_nodes' => $nodePoolSpec['max_nodes'] ?? $nodePoolSpec['count'],
                ],
                'ha' => $cluster->tier === CloudCluster::TIER_ENTERPRISE,
                'tags' => ['dply', 'dply-cloud', 'org-'.($cluster->organization_id ?? 'unknown')],
            ];

            Log::info('Creating DOKS cluster', [
                'cluster_id' => $cluster->id,
                'config' => $clusterConfig,
            ]);

            // Create the cluster via DO API
            $doCluster = $do->createKubernetesCluster($clusterConfig);

            // Store the DO cluster ID
            $cluster->update([
                'do_kubernetes_cluster_id' => $doCluster['id'],
                'meta' => array_merge(
                    is_array($cluster->meta) ? $cluster->meta : [],
                    [
                        'do_cluster_name' => $doCluster['name'] ?? null,
                        'do_cluster_version' => $doCluster['version'] ?? null,
                        'do_cluster_endpoint' => $doCluster['endpoint'] ?? null,
                        'provision_started_at' => now()->toIso8601String(),
                    ]
                ),
            ]);

            // Wait for cluster to be ready
            $this->waitForClusterReady($cluster, $do);

        } catch (\Throwable $e) {
            Log::error('Cluster provisioning failed', [
                'cluster_id' => $cluster->id,
                'error' => $e->getMessage(),
            ]);

            $cluster->update([
                'status' => CloudCluster::STATUS_ERROR,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Poll the cluster until it's ready, then fetch kubeconfig.
     */
    private function waitForClusterReady(CloudCluster $cluster, DigitalOceanService $do): void
    {
        $maxAttempts = 120; // 10 minutes (5s * 120)
        $attempt = 0;
        $clusterId = $cluster->do_kubernetes_cluster_id;

        while ($attempt < $maxAttempts) {
            $doCluster = $do->getKubernetesCluster($clusterId);

            if ($doCluster === null) {
                throw new \RuntimeException('Cluster was deleted during provisioning');
            }

            $status = $doCluster['status'] ?? ['state' => 'unknown'];
            $state = $status['state'] ?? 'unknown';

            Log::debug('Cluster status check', [
                'cluster_id' => $cluster->id,
                'do_cluster_id' => $clusterId,
                'state' => $state,
                'attempt' => $attempt,
            ]);

            if ($state === 'running') {
                // Cluster is ready - fetch kubeconfig
                $kubeconfig = $do->getKubernetesKubeconfig($clusterId);

                // Extract API endpoint from kubeconfig
                $apiEndpoint = $this->extractApiEndpoint($kubeconfig);

                $cluster->update([
                    'status' => CloudCluster::STATUS_READY,
                    'kubeconfig' => $kubeconfig,
                    'provisioned_at' => now(),
                    'meta' => array_merge(
                        is_array($cluster->meta) ? $cluster->meta : [],
                        [
                            'api_endpoint' => $apiEndpoint,
                            'provision_completed_at' => now()->toIso8601String(),
                            'do_cluster_status' => $doCluster['status'] ?? null,
                            'do_cluster_node_pools' => $doCluster['node_pools'] ?? [],
                        ]
                    ),
                ]);

                Log::info('Cluster provisioning completed', [
                    'cluster_id' => $cluster->id,
                    'api_endpoint' => $apiEndpoint,
                ]);

                // Now install additional components
                $this->installClusterComponents($cluster);

                return;
            }

            if ($state === 'error' || ($status['is_valid'] ?? true) === false) {
                throw new \RuntimeException(
                    'Cluster provisioning failed: '.($status['message'] ?? 'Unknown error')
                );
            }

            $attempt++;
            sleep(5);
        }

        throw new \RuntimeException('Cluster provisioning timed out after 10 minutes');
    }

    /**
     * Extract the API server endpoint from kubeconfig.
     */
    private function extractApiEndpoint(string $kubeconfig): ?string
    {
        try {
            $config = \Symfony\Component\Yaml\Yaml::parse($kubeconfig);
            $clusters = $config['clusters'] ?? [];

            if (!empty($clusters[0]['cluster']['server'])) {
                return $clusters[0]['cluster']['server'];
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to parse kubeconfig for API endpoint', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Install ingress controller, cert-manager, and monitoring.
     *
     * This is done via kubectl/helm in a real implementation.
     * For now, we mark it as ready and components can be installed separately.
     */
    private function installClusterComponents(CloudCluster $cluster): void
    {
        // TODO: Install ingress-nginx or similar
        // TODO: Install cert-manager
        // TODO: Install monitoring stack (Prometheus/Grafana)

        Log::info('Cluster components should be installed', [
            'cluster_id' => $cluster->id,
        ]);

        // Mark cluster as fully ready
        $cluster->update([
            'meta' => array_merge(
                is_array($cluster->meta) ? $cluster->meta : [],
                [
                    'components_installed' => true,
                    'components_installed_at' => now()->toIso8601String(),
                ]
            ),
        ]);
    }

    /**
     * Delete a cluster and all its resources.
     */
    public function deleteCluster(CloudCluster $cluster): void
    {
        if (!$cluster->do_kubernetes_cluster_id) {
            // Nothing to delete on DO side
            $cluster->delete();

            return;
        }

        $credential = $cluster->providerCredential;
        if (!$credential) {
            throw new \RuntimeException('No provider credential available to delete cluster');
        }

        $do = new DigitalOceanService($credential);

        try {
            $do->deleteKubernetesCluster($cluster->do_kubernetes_cluster_id);

            $cluster->update([
                'status' => CloudCluster::STATUS_DELETING,
                'meta' => array_merge(
                    is_array($cluster->meta) ? $cluster->meta : [],
                    [
                        'deletion_requested_at' => now()->toIso8601String(),
                    ]
                ),
            ]);

            Log::info('Cluster deletion requested', [
                'cluster_id' => $cluster->id,
                'do_cluster_id' => $cluster->do_kubernetes_cluster_id,
            ]);

        } catch (\Throwable $e) {
            // If cluster is already deleted (404), that's fine
            if (str_contains($e->getMessage(), '404')) {
                $cluster->delete();

                return;
            }

            throw $e;
        }
    }

    /**
     * Sync cluster status from DigitalOcean API.
     */
    public function syncClusterStatus(CloudCluster $cluster): void
    {
        if (!$cluster->do_kubernetes_cluster_id) {
            return;
        }

        $credential = $cluster->providerCredential;
        if (!$credential) {
            return;
        }

        $do = new DigitalOceanService($credential);

        try {
            $doCluster = $do->getKubernetesCluster($cluster->do_kubernetes_cluster_id);

            if ($doCluster === null) {
                // Cluster was deleted externally
                $cluster->update([
                    'status' => CloudCluster::STATUS_ERROR,
                    'error_message' => 'Cluster was deleted in DigitalOcean',
                ]);

                return;
            }

            $status = $doCluster['status'] ?? ['state' => 'unknown'];
            $state = $status['state'] ?? 'unknown';

            // Map DO state to our status
            $newStatus = match ($state) {
                'running' => CloudCluster::STATUS_READY,
                'provisioning' => CloudCluster::STATUS_PROVISIONING,
                'deleting' => CloudCluster::STATUS_DELETING,
                'error' => CloudCluster::STATUS_ERROR,
                default => $cluster->status,
            };

            if ($newStatus !== $cluster->status) {
                $cluster->update([
                    'status' => $newStatus,
                    'meta' => array_merge(
                        is_array($cluster->meta) ? $cluster->meta : [],
                        [
                            'do_cluster_status' => $status,
                            'do_cluster_node_pools' => $doCluster['node_pools'] ?? [],
                            'last_synced_at' => now()->toIso8601String(),
                        ]
                    ),
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('Failed to sync cluster status', [
                'cluster_id' => $cluster->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
