<?php

declare(strict_types=1);

namespace App\Modules\Queue\Support;

use App\Models\Organization;
use App\Modules\Logs\Services\ServerLogEntitlements;

/**
 * Resolves an org's dply Queue entitlements from config — the launch defaults
 * overlaid with the override for the org's current subscription plan.
 *
 * The single place plan → queue limits is decided, so namespace creation, the
 * per-namespace rate limiter, push rejection, billing, and the UI all read the
 * same numbers. Mirrors {@see ServerLogEntitlements}.
 */
class QueueEntitlements
{
    public function for(Organization $organization): QueueEntitlement
    {
        $defaults = (array) config('queue_service.entitlements.defaults', []);
        $plans = (array) config('queue_service.entitlements.plans', []);

        $planKey = (string) $organization->currentSubscriptionPlan()['key'];
        $override = is_array($plans[$planKey] ?? null) ? $plans[$planKey] : [];

        return QueueEntitlement::fromConfig($planKey, $defaults, $override);
    }
}
