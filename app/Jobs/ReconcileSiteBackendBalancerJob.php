<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LoadBalancer;
use App\Models\Site;
use App\Services\Sites\Backends\SiteBackendBalancerSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Waits for a freshly-provisioned HAProxy host to come up, then writes its config
 * and registers the site's active backends. Re-entrant: re-dispatches itself
 * ~30s later until the host is ready (HAProxy is installed at provision time, so
 * once the box is provisioned the config can be written). Software substrate
 * only — Hetzner cloud LBs are driven by ProvisionHetznerLoadBalancerJob.
 * See docs/MULTI_BACKEND_SITES.md.
 */
class ReconcileSiteBackendBalancerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const MAX_ATTEMPTS = 60;

    private const RETRY_SECONDS = 30;

    public function __construct(
        public string $loadBalancerId,
        public int $attempt = 1,
    ) {}

    public function handle(SiteBackendBalancerSync $balancerSync): void
    {
        $lb = LoadBalancer::query()->with('server')->find($this->loadBalancerId);
        if ($lb === null || ! $lb->isSoftware()) {
            return;
        }

        $host = $lb->server;
        if ($host === null) {
            return;
        }

        if (! $host->isProvisioningComplete()) {
            if ($this->attempt < self::MAX_ATTEMPTS) {
                self::dispatch($this->loadBalancerId, $this->attempt + 1)
                    ->delay(now()->addSeconds(self::RETRY_SECONDS));
            }

            return;
        }

        // Host is up (HAProxy installed at provision time). Capture its address,
        // then reconcile the site's backends onto it — sync() writes the config
        // via ConfigureHAProxyLoadBalancerJob.
        $lb->update([
            'status' => LoadBalancer::STATUS_RUNNING,
            'public_ipv4' => $host->ip_address,
            'private_ip' => $host->private_ip_address,
        ]);

        $site = $this->siteForLoadBalancer($lb);
        if ($site !== null) {
            $balancerSync->sync($site);
        }
    }

    private function siteForLoadBalancer(LoadBalancer $lb): ?Site
    {
        return Site::query()
            ->where('meta->backend_group->load_balancer_id', (string) $lb->id)
            ->first();
    }
}
