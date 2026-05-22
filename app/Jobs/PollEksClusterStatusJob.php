<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Services\AwsEksService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EKS counterpart to {@see PollDoksClusterStatusJob}. Same contract, same
 * cadence, same terminal-state semantics — different API shape. EKS-state
 * mapping:
 *   - ACTIVE                  → server READY, fetch+store kubeconfig, stop.
 *   - FAILED / DELETING / DELETE_FAILED → server ERROR with AWS message, stop.
 *   - CREATING / UPDATING / PENDING (unknown) → reschedule for another poll.
 *
 * EKS doesn't return cluster status the same way DOKS does (separate
 * DescribeCluster + ListNodegroups + DescribeNodegroup calls — see
 * AwsEksService::listAndDescribeNodegroups) but the shape we write into
 * server.meta.kubernetes.snapshot lines up with what the WorkspaceCluster
 * blade expects so the same node-pool table renders for both providers.
 */
class PollEksClusterStatusJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 30;

    private const POLL_INTERVAL_SECONDS = 60;

    public function __construct(public Server $server) {}

    public function handle(): void
    {
        $server = $this->server->fresh();
        if ($server === null) {
            return;
        }

        $kubernetes = is_array($server->meta['kubernetes'] ?? null) ? $server->meta['kubernetes'] : [];
        $clusterName = (string) ($kubernetes['cluster_name'] ?? '');
        $region = (string) ($kubernetes['region'] ?? '');

        if ($clusterName === '') {
            Log::warning('eks.poll.skip_missing_cluster_name', [
                'server_id' => $server->getKey(),
                'attempt' => $this->attempts(),
            ]);

            return;
        }

        if (! in_array($server->status, [Server::STATUS_PENDING, Server::STATUS_PROVISIONING, Server::STATUS_READY], true)) {
            return;
        }

        $credential = $server->providerCredential;
        if ($credential === null) {
            $this->markError($server, __('Provider credential is missing — cannot poll cluster status.'));

            return;
        }

        try {
            $service = new AwsEksService($credential, $region !== '' ? $region : null);
            $cluster = $service->getCluster($clusterName);
        } catch (Throwable $e) {
            Log::info('eks.poll.transient_failure', [
                'server_id' => $server->getKey(),
                'cluster_name' => $clusterName,
                'region' => $region,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);
            $this->release(self::POLL_INTERVAL_SECONDS);

            return;
        }

        if ($cluster === null) {
            $this->markError($server, __('Cluster no longer exists in AWS (region :region).', ['region' => $region]));

            return;
        }

        $status = (string) ($cluster['status'] ?? 'UNKNOWN');

        try {
            $nodegroups = $service->listAndDescribeNodegroups($clusterName);
        } catch (Throwable $e) {
            // Cluster describe succeeded but nodegroups failed — write what we
            // have and keep polling. Degraded but recoverable.
            $nodegroups = [];
            Log::info('eks.poll.nodegroups_fetch_failed', [
                'server_id' => $server->getKey(),
                'cluster_name' => $clusterName,
                'error' => $e->getMessage(),
            ]);
        }

        $this->persistSnapshot($server, $cluster, $nodegroups, $status);

        if ($status === 'ACTIVE') {
            $this->markActive($server, $service, $cluster);

            return;
        }

        if (in_array($status, ['FAILED', 'DELETING', 'DELETE_FAILED'], true)) {
            $this->markError($server, __('AWS reports EKS cluster :status.', ['status' => strtolower($status)]));

            return;
        }

        // CREATING / UPDATING / PENDING / unknown — keep polling.
        $this->release(self::POLL_INTERVAL_SECONDS);
    }

    public function failed(?Throwable $exception): void
    {
        $server = $this->server->fresh();
        if ($server === null || $server->status === Server::STATUS_READY) {
            return;
        }

        $this->markError(
            $server,
            __('EKS cluster did not reach ACTIVE within :minutes minutes. Check the AWS console.', [
                'minutes' => (int) (($this->tries * self::POLL_INTERVAL_SECONDS) / 60),
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $cluster
     * @param  list<array<string, mixed>>  $nodegroups
     */
    private function persistSnapshot(Server $server, array $cluster, array $nodegroups, string $status): void
    {
        $meta = $server->meta ?? [];
        $kubernetes = is_array($meta['kubernetes'] ?? null) ? $meta['kubernetes'] : [];

        // Normalize the snapshot into the same shape the DOKS poller writes,
        // so the WorkspaceCluster blade can render either provider without
        // branching. version/ha/region pulled from the AWS cluster payload.
        $kubernetes['snapshot'] = [
            'id' => (string) ($cluster['arn'] ?? ''),
            'name' => (string) ($cluster['name'] ?? ''),
            'region' => (string) ($cluster['endpointConfig']['publicAccess'] ?? ''), // placeholder; region lives in meta
            'version' => (string) ($cluster['version'] ?? ''),
            'ha' => true, // EKS control plane is always multi-AZ HA
            'node_pools' => $nodegroups,
            'status' => ['state' => strtolower($status)],
        ];
        // EKS gives no equivalent of DOKS "region" inside the cluster payload —
        // it's implicit in the API endpoint we hit. Surface it via meta.
        $kubernetes['snapshot']['region'] = (string) ($kubernetes['region'] ?? '');
        $kubernetes['state'] = strtolower($status);
        $kubernetes['last_polled_at'] = now()->toIso8601String();

        $meta['kubernetes'] = $kubernetes;
        $server->update(['meta' => $meta]);
    }

    /**
     * @param  array<string, mixed>  $cluster
     */
    private function markActive(Server $server, AwsEksService $service, array $cluster): void
    {
        $meta = $server->meta ?? [];
        $kubernetes = is_array($meta['kubernetes'] ?? null) ? $meta['kubernetes'] : [];

        try {
            $kubernetes['kubeconfig'] = $service->generateKubeconfig($cluster);
            $kubernetes['kubeconfig_fetched_at'] = now()->toIso8601String();
        } catch (Throwable $e) {
            Log::warning('eks.poll.kubeconfig_build_failed', [
                'server_id' => $server->getKey(),
                'error' => $e->getMessage(),
            ]);
        }

        unset($kubernetes['last_error']);
        $meta['kubernetes'] = $kubernetes;
        $server->update([
            'status' => Server::STATUS_READY,
            'health_status' => Server::HEALTH_REACHABLE,
            'meta' => $meta,
        ]);
    }

    private function markError(Server $server, string $message): void
    {
        $meta = $server->meta ?? [];
        $kubernetes = is_array($meta['kubernetes'] ?? null) ? $meta['kubernetes'] : [];
        $kubernetes['last_error'] = $message;
        $kubernetes['errored_at'] = now()->toIso8601String();
        $meta['kubernetes'] = $kubernetes;
        $server->update([
            'status' => Server::STATUS_ERROR,
            'health_status' => Server::HEALTH_UNREACHABLE,
            'meta' => $meta,
        ]);
    }
}
