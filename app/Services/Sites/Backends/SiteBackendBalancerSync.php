<?php

declare(strict_types=1);

namespace App\Services\Sites\Backends;

use App\Jobs\ConfigureHAProxyLoadBalancerJob;
use App\Jobs\SyncHetznerLoadBalancerTargetsJob;
use App\Models\LoadBalancer;
use App\Models\LoadBalancerTarget;
use App\Models\Site;
use App\Models\SiteBackend;
use Illuminate\Support\Facades\Log;

/**
 * Renders a multi-backend site's balancer config FROM its {@see SiteBackend}
 * rows: reconciles the LB's targets (weight + drain) to match, then triggers the
 * substrate apply (HAProxy reload / Hetzner target add-remove). This is the
 * keystone P5/P6 build on — rolling drains a backend and canary shifts a
 * backend's weight, both by mutating the row and calling sync().
 *
 * No-op (logged) until the group has a balancer linked
 * (`meta.backend_group.load_balancer_id`), so it's safe to call from the
 * reconciler before the LB exists. See docs/MULTI_BACKEND_SITES.md.
 */
class SiteBackendBalancerSync
{
    public function sync(Site $site): void
    {
        $group = $site->backendGroup();
        if (! ($group['enabled'] ?? false)) {
            return;
        }

        $lbId = (string) ($group['load_balancer_id'] ?? '');
        if ($lbId === '') {
            Log::info('site-backend balancer sync: no load balancer linked yet — skipping', [
                'site_id' => (string) $site->id,
            ]);

            return;
        }

        $lb = LoadBalancer::query()->find($lbId);
        if ($lb === null) {
            return;
        }

        // Backends that belong in the balancer: `active` (in rotation) and
        // `draining` (kept but disabled so in-flight requests finish). Anything
        // still provisioning/deploying or errored isn't serving yet.
        $backends = $site->backends()
            ->whereIn('state', [SiteBackend::STATE_ACTIVE, SiteBackend::STATE_DRAINING])
            ->with('server')
            ->get();

        $desiredServerIds = [];
        foreach ($backends as $backend) {
            /** @var SiteBackend $backend */
            if ($backend->server === null) {
                continue;
            }
            $desiredServerIds[] = $backend->server_id;
            $drained = $backend->drained_at !== null || $backend->state === SiteBackend::STATE_DRAINING;

            LoadBalancerTarget::query()->updateOrCreate(
                ['load_balancer_id' => $lb->id, 'server_id' => $backend->server_id],
                [
                    'provider_server_id' => $backend->server->provider_id,
                    'status' => 'active',
                    'weight' => max(0, (int) $backend->weight),
                    'drained_at' => $drained ? ($backend->drained_at ?? now()) : null,
                ],
            );
        }

        // Drop targets for servers no longer backing this site. The group's LB is
        // dedicated to the site, so its target set IS the backend set.
        LoadBalancerTarget::query()
            ->where('load_balancer_id', $lb->id)
            ->whereNotIn('server_id', $desiredServerIds !== [] ? $desiredServerIds : ['__none__'])
            ->delete();

        // Apply on the substrate. HAProxy re-renders with weight/disabled;
        // Hetzner reconciles its target set (no per-target weight there).
        if ($lb->isSoftware()) {
            ConfigureHAProxyLoadBalancerJob::dispatch($lb->id);
        } else {
            SyncHetznerLoadBalancerTargetsJob::dispatch($lb->id);
        }
    }
}
