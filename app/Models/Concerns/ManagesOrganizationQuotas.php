<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesOrganizationQuotas
{


    /**
     * The org's current plan site ceiling, or null when unlimited.
     */
    public function planSiteLimit(): ?int
    {
        // Beta orgs use the roomy beta site ceiling instead of the plan tier.
        if ($this->isBeta()) {
            return max(1, (int) config('subscription.standard.beta.sites', 25));
        }

        return $this->currentSubscriptionPlan()['max_sites'];
    }

    /**
     * The org's current plan-tier server ceiling, or null when unlimited
     * (Business). This is the per-tier ALLOTMENT shown in the UI — distinct from
     * {@see maxServers()}, which is the creation gate and is intentionally
     * uncapped because adding a server simply bumps the usage-based tier.
     */
    public function planServerLimit(): ?int
    {
        // Beta orgs are bounded by the BYO envelope, not the plan tier.
        if ($this->isBeta()) {
            return $this->betaByoServerLimit();
        }

        return $this->currentSubscriptionPlan()['max_servers'];
    }

    /**
     * Number of sites that count against the plan's site ceiling. Preview
     * deployments (Edge/Cloud) are scratch clones of a parent and never
     * consume quota.
     */
    public function quotaCountedSiteCount(): int
    {
        return $this->sites()
            ->get()
            ->reject(fn (Site $site) => $site->isEdgePreview() || $site->isCloudPreview())
            ->count();
    }

    /**
     * True when the org has reached its plan's site ceiling.
     */
    public function siteLimitReached(): bool
    {
        $limit = $this->planSiteLimit();

        return $limit !== null && $this->quotaCountedSiteCount() >= $limit;
    }

    /**
     * Friendly upgrade prompt shown when site creation is blocked.
     */
    public function siteLimitMessage(): string
    {
        $plan = $this->currentSubscriptionPlan();
        $limit = $plan['max_sites'];

        if ($limit === null) {
            return '';
        }

        return sprintf(
            'Your %s plan includes %d %s. Add a server to move up to the next plan, or contact us to raise your limit.',
            $plan['label'],
            $limit,
            $limit === 1 ? 'site' : 'sites',
        );
    }

    /**
     * IDs of every server owned by this org, memoized for the request.
     *
     * @return Collection<int, string>
     */
    public function serverIds(): Collection
    {
        return $this->serverIdsMemo ??= $this->servers()->pluck('id');
    }

    /**
     * BYO VMs that count against the beta BYO ceiling (excludes the free managed
     * box and managed-product logical hosts).
     */
    public function byoServerCount(): int
    {
        return $this->servers()
            ->where('hosting_backend', Server::HOSTING_BACKEND_BYO)
            ->get()
            ->reject(fn (Server $server) => $server->isManagedProductHost())
            ->count();
    }

    /**
     * dply-managed VMs the org currently holds (the free-CX22 grant counter).
     */
    public function managedServerCount(): int
    {
        return $this->servers()
            ->where('hosting_backend', Server::HOSTING_BACKEND_DPLY)
            ->get()
            ->filter(fn (Server $server) => $server->isManagedVm())
            ->count();
    }

    /**
     * Whether the org can provision another free dply-managed server. During
     * beta this enforces the single-CX22 grant; outside beta managed servers
     * aren't capped here (availability is gated by the surface flag + platform
     * config at the create flow).
     */
    public function canCreateManagedServer(): bool
    {
        if (! $this->isBeta()) {
            return true;
        }

        return $this->managedServerCount() < $this->betaManagedServerLimit();
    }

    /**
     * Maximum number of BYO servers allowed. Unlimited under the Standard model
     * — trial-state gating handles the cash-burning abuse case — but bounded for
     * beta orgs by the beta envelope.
     */
    public function maxServers(): int
    {
        return $this->isBeta() ? $this->betaByoServerLimit() : PHP_INT_MAX;
    }

    /**
     * Maximum sites allowed on the org's current plan. Returns PHP_INT_MAX for
     * the unlimited (Business / null) ceiling so callers can compare numerically.
     */
    public function maxSites(): int
    {
        return $this->planSiteLimit() ?? PHP_INT_MAX;
    }

    /**
     * Whether the organization can create another server (under limit).
     */
    public function canCreateServer(): bool
    {
        // Beta orgs are bounded by the BYO envelope (the free managed box is
        // counted separately via canCreateManagedServer); otherwise unlimited.
        if ($this->isBeta()) {
            return $this->byoServerCount() < $this->maxServers();
        }

        return $this->servers()->count() < $this->maxServers();
    }

    /**
     * Whether the organization can create another site under its current
     * plan's site ceiling. Preview deployments don't consume quota — see
     * {@see quotaCountedSiteCount()}.
     */
    public function canCreateSite(): bool
    {
        return ! $this->siteLimitReached();
    }

    /**
     * Human-readable server cap for the current plan (e.g. "3", "Unlimited").
     */
    public function maxServersDisplay(): string
    {
        $m = $this->maxServers();

        return $m >= PHP_INT_MAX ? 'Unlimited' : (string) $m;
    }

    /**
     * Human-readable site cap for the current plan (e.g. "10", "Unlimited").
     */
    public function maxSitesDisplay(): string
    {
        $m = $this->maxSites();

        return $m >= PHP_INT_MAX ? 'Unlimited' : (string) $m;
    }
}
