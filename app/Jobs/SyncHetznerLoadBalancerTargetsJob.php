<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LoadBalancer;
use App\Services\HetznerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Reconciles a Hetzner cloud LB's targets to match its {@see LoadBalancerTarget}
 * rows: adds non-drained targets, removes drained/removed ones. Hetzner LBs have
 * no per-target weight, so this is the cloud substrate's add/remove-only apply
 * (canary, which needs weights, is HAProxy-only). Best-effort per target.
 * See docs/MULTI_BACKEND_SITES.md.
 */
class SyncHetznerLoadBalancerTargetsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public int $backoff = 15;

    public function __construct(
        public string $loadBalancerId,
    ) {}

    public function handle(): void
    {
        $lb = LoadBalancer::query()
            ->with(['targets.server', 'providerCredential'])
            ->find($this->loadBalancerId);

        if ($lb === null || $lb->isSoftware() || $lb->providerCredential === null) {
            return;
        }

        $providerLbId = (int) $lb->provider_id;
        if ($providerLbId === 0) {
            return;
        }

        $hetzner = new HetznerService($lb->providerCredential);
        $usePrivate = $lb->hetzner_network_id !== null;

        foreach ($lb->targets as $target) {
            $providerServerId = (int) ($target->server->provider_id ?? $target->provider_server_id ?? 0);
            if ($providerServerId === 0) {
                continue;
            }

            // Drained targets leave rotation entirely (no weight knob on Hetzner).
            $shouldServe = $target->drained_at === null;

            try {
                if ($shouldServe) {
                    $hetzner->addLoadBalancerTarget($providerLbId, $providerServerId, $usePrivate);
                } else {
                    $hetzner->removeLoadBalancerTarget($providerLbId, $providerServerId);
                }
            } catch (\Throwable $e) {
                // add_target on an existing target / remove on a missing one both
                // throw — benign for a reconcile. Log anything else and continue.
                Log::info('hetzner LB target reconcile: skipped a target', [
                    'load_balancer_id' => $lb->id,
                    'provider_server_id' => $providerServerId,
                    'serve' => $shouldServe,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
