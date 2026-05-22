<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Services\DigitalOceanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Self-rescheduling poller for DOKS clusters dply created (or registered) on
 * behalf of the user. Fetches /kubernetes/clusters/{id} once a minute and
 * mirrors the cluster's status onto the local Server row so the rest of the
 * app (server list, /servers/{id}/cluster page, gates) sees correct state
 * without depending on any user having the cluster page open.
 *
 * Terminal states + their side effects:
 *   - state=running   → flip server.status=READY, fetch + persist kubeconfig
 *   - state=error|degraded → flip server.status=ERROR with last_error
 *   - 30-attempt cap reached → flip server.status=ERROR with timeout reason
 *
 * Non-terminal (state=provisioning, transient DO API blip) → release back to
 * the queue with a 60s delay. The dispatch site decides the initial timing.
 */
class PollDoksClusterStatusJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /** Max polls before we give up — 30 × 60s = 30 min envelope. */
    public int $tries = 30;

    /** Seconds between polls. Tuned to DO's typical 5-10 min provision window. */
    private const POLL_INTERVAL_SECONDS = 60;

    public function __construct(public Server $server) {}

    public function handle(): void
    {
        $server = $this->server->fresh();
        if ($server === null) {
            // Row was deleted while we were polling — nothing to do.
            return;
        }

        $clusterId = (string) ($server->meta['kubernetes']['cluster_id'] ?? '');
        if ($clusterId === '') {
            Log::warning('doks.poll.skip_missing_cluster_id', [
                'server_id' => $server->getKey(),
                'attempt' => $this->attempts(),
            ]);

            return;
        }

        if (! in_array($server->status, [Server::STATUS_PENDING, Server::STATUS_PROVISIONING, Server::STATUS_READY], true)) {
            // Server already terminal (ERROR / DISCONNECTED) or transitioned out
            // of provisioning some other way — no reason to keep polling.
            return;
        }

        $credential = $server->providerCredential;
        if ($credential === null) {
            $this->markError($server, __('Provider credential is missing — cannot poll cluster status.'));

            return;
        }

        try {
            $service = new DigitalOceanService($credential);
            $cluster = $service->getKubernetesCluster($clusterId);
        } catch (Throwable $e) {
            // Transient API failure — release and retry. The $tries cap will
            // eventually mark us as failed if DO stays down.
            Log::info('doks.poll.transient_failure', [
                'server_id' => $server->getKey(),
                'cluster_id' => $clusterId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);
            $this->release(self::POLL_INTERVAL_SECONDS);

            return;
        }

        if ($cluster === null) {
            // 404 — cluster vanished from DO. Stop polling and mark error so
            // the user sees "cluster not found in DigitalOcean".
            $this->markError($server, __('Cluster no longer exists in DigitalOcean.'));

            return;
        }

        $state = (string) ($cluster['status']['state'] ?? 'unknown');
        $this->persistSnapshot($server, $cluster, $state);

        if ($state === 'running') {
            $this->markRunning($server, $service, $clusterId);

            return;
        }

        if (in_array($state, ['error', 'degraded'], true)) {
            $message = (string) ($cluster['status']['message'] ?? '');
            $this->markError(
                $server,
                $message !== ''
                    ? __('DigitalOcean reports cluster :state: :detail', ['state' => $state, 'detail' => $message])
                    : __('DigitalOcean reports cluster :state.', ['state' => $state]),
            );

            return;
        }

        // Provisioning, upgrading, or unknown — keep polling.
        $this->release(self::POLL_INTERVAL_SECONDS);
    }

    /**
     * Final fallback when the queue runner gives up on us (e.g. attempt cap
     * reached). Mark the server ERROR so the UI surfaces a timeout instead of
     * sitting in PROVISIONING forever.
     */
    public function failed(?Throwable $exception): void
    {
        $server = $this->server->fresh();
        if ($server === null || $server->status === Server::STATUS_READY) {
            return;
        }

        $this->markError(
            $server,
            __('Cluster did not finish provisioning within :minutes minutes. Check the DigitalOcean console.', [
                'minutes' => (int) (($this->tries * self::POLL_INTERVAL_SECONDS) / 60),
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $cluster
     */
    private function persistSnapshot(Server $server, array $cluster, string $state): void
    {
        $meta = $server->meta ?? [];
        $meta['kubernetes'] = array_merge(is_array($meta['kubernetes'] ?? null) ? $meta['kubernetes'] : [], [
            'snapshot' => $cluster,
            'state' => $state,
            'last_polled_at' => now()->toIso8601String(),
        ]);
        $server->update(['meta' => $meta]);
    }

    private function markRunning(Server $server, DigitalOceanService $service, string $clusterId): void
    {
        $meta = $server->meta ?? [];
        $k8s = is_array($meta['kubernetes'] ?? null) ? $meta['kubernetes'] : [];

        try {
            $kubeconfig = $service->getKubernetesClusterKubeconfig($clusterId);
            if (trim($kubeconfig) !== '') {
                $k8s['kubeconfig'] = $kubeconfig;
                $k8s['kubeconfig_fetched_at'] = now()->toIso8601String();
            }
        } catch (Throwable $e) {
            // Don't fail the ready transition just because kubeconfig fetch
            // hiccupped — the page can offer a refetch button. Log it.
            Log::warning('doks.poll.kubeconfig_fetch_failed', [
                'server_id' => $server->getKey(),
                'cluster_id' => $clusterId,
                'error' => $e->getMessage(),
            ]);
        }

        unset($k8s['last_error']);
        $meta['kubernetes'] = $k8s;
        $server->update([
            'status' => Server::STATUS_READY,
            'health_status' => Server::HEALTH_REACHABLE,
            'meta' => $meta,
        ]);
    }

    private function markError(Server $server, string $message): void
    {
        $meta = $server->meta ?? [];
        $k8s = is_array($meta['kubernetes'] ?? null) ? $meta['kubernetes'] : [];
        $k8s['last_error'] = $message;
        $k8s['errored_at'] = now()->toIso8601String();
        $meta['kubernetes'] = $k8s;
        $server->update([
            'status' => Server::STATUS_ERROR,
            'health_status' => Server::HEALTH_UNREACHABLE,
            'meta' => $meta,
        ]);
    }
}
