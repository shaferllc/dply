<?php

declare(strict_types=1);

namespace App\Modules\Cache\Support;

use App\Models\Organization;
use App\Modules\Queue\Support\QueueEntitlements;

/**
 * Resolves an org's dply Cache entitlements from config — the launch defaults
 * overlaid with the override for the org's current subscription plan.
 *
 * The single place plan → cache limits is decided, so creation, the UI, and the
 * attach flow all read the same numbers. Mirrors {@see QueueEntitlements}.
 *
 * There is deliberately no throughput or capacity entitlement here. The shared
 * tier is free for every org and bounded by BYTES rather than by plan
 * (docs/adr/dply-cache.md, decisions 7 and 16), so the only thing a plan
 * decides is how many caches an org may hold.
 */
class CacheEntitlements
{
    public function for(Organization $organization): CacheEntitlement
    {
        $defaults = (array) config('cache_service.entitlements.defaults', []);
        $plans = (array) config('cache_service.entitlements.plans', []);

        $planKey = (string) $organization->currentSubscriptionPlan()['key'];
        $override = is_array($plans[$planKey] ?? null) ? $plans[$planKey] : [];

        return CacheEntitlement::fromConfig($planKey, $defaults, $override);
    }
}
