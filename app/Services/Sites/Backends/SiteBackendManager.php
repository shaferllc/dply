<?php

declare(strict_types=1);

namespace App\Services\Sites\Backends;

use App\Jobs\DestroySiteBackendJob;
use App\Jobs\ReconcileSiteBackendsJob;
use App\Models\Site;
use App\Models\SiteBackend;
use App\Models\User;
use RuntimeException;

/**
 * Entry point for multi-backend sites: enable the backend group, add/remove
 * backends, and kick the reconciler. Provisioning + replication + deploy is
 * driven by {@see ReconcileSiteBackendsJob}; teardown by
 * {@see DestroySiteBackendJob}. See docs/MULTI_BACKEND_SITES.md.
 */
class SiteBackendManager
{
    /** Substrates that front a backend group; canary needs a weight-capable one. */
    public const SUBSTRATE_HAPROXY = 'haproxy';

    public const SUBSTRATE_HETZNER = 'hetzner';

    public function __construct(
        private readonly SiteBackendProvisioner $provisioner,
        private readonly SiteBackendBalancerSync $balancerSync,
        private readonly SiteBackendBalancerProvisioner $balancerProvisioner,
    ) {}

    /**
     * Register the site's own server as the primary backend (idempotent). Always
     * the first row in a group — the live serving point that's never torn down.
     */
    public function ensurePrimary(Site $site): SiteBackend
    {
        if ($site->server_id === null) {
            throw new RuntimeException(__('This site has no server.'));
        }

        return SiteBackend::query()->firstOrCreate(
            ['site_id' => $site->id, 'server_id' => $site->server_id],
            [
                'role' => SiteBackend::ROLE_PRIMARY,
                'state' => SiteBackend::STATE_ACTIVE,
                'weight' => 100,
                'backend_site_id' => null,
            ],
        );
    }

    /**
     * Provision and attach a new backend, enabling the group on first add.
     *
     * @param  array{region?: string, size?: string, provider?: string, provider_credential_id?: string, substrate?: string}  $placement
     */
    public function addBackend(Site $site, array $placement = []): SiteBackend
    {
        if ($site->server === null) {
            throw new RuntimeException(__('This site has no server to clone a backend from.'));
        }

        $this->ensureGroupEnabled($site, $placement['substrate'] ?? null);
        $this->ensurePrimary($site);

        // Provision the balancer on the first add (no-op once linked). Without it
        // the group has no traffic front and rolling/canary stay hidden.
        if ((string) ($site->fresh()->backendGroup()['load_balancer_id'] ?? '') === '') {
            $this->balancerProvisioner->provision($site->fresh());
        }

        $backend = $this->provisioner->provision($site, $placement);

        ReconcileSiteBackendsJob::dispatch((string) $site->id);

        return $backend;
    }

    /**
     * Drain a backend from rotation and tear it down. The balancer-detach (4c)
     * runs first via {@see drain()}; here we just flip state and queue the
     * destroy. Refuses the primary.
     */
    public function removeBackend(SiteBackend $backend, ?User $actor = null): void
    {
        if ($backend->isPrimary()) {
            throw new RuntimeException(__('Promote another backend before removing the primary.'));
        }

        $backend->forceFill([
            'state' => SiteBackend::STATE_DRAINING,
            'drained_at' => now(),
        ])->save();

        // Re-render the balancer so the draining backend stops taking NEW traffic
        // (HAProxy `disabled` / Hetzner target removed) while in-flight requests
        // finish, before the box is destroyed.
        $backend->loadMissing('site');
        if ($backend->site !== null) {
            $this->balancerSync->sync($backend->site);
        }

        DestroySiteBackendJob::dispatch((string) $backend->id, $actor?->id);
    }

    /**
     * Mark the group enabled and pin its substrate (defaults to HAProxy, which
     * supports the weights canary needs). Stored on site meta; rows are the
     * source of truth for membership.
     */
    private function ensureGroupEnabled(Site $site, ?string $substrate): void
    {
        $meta = ($site->meta );
        $group = is_array($meta['backend_group'] ?? null) ? $meta['backend_group'] : [];

        $group['enabled'] = true;
        $group['substrate'] = ($group['substrate'] ?? null)
            ?: ($substrate === self::SUBSTRATE_HETZNER ? self::SUBSTRATE_HETZNER : self::SUBSTRATE_HAPROXY);

        $meta['backend_group'] = $group;
        $site->forceFill(['meta' => $meta])->save();
    }
}
