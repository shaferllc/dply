<?php

declare(strict_types=1);

namespace App\Modules\Logs\Services;

use App\Models\Organization;

/**
 * Resolves an org's dply Logs entitlements from config — the free-MVP defaults
 * overlaid with the override for the org's current subscription plan
 * (free/starter/pro/business). The single place plan → log limits is decided,
 * so the gate, billing (PR C), and UI all read the same numbers.
 *
 * See docs/SERVER_LOGS_BILLING.md §1.2.
 */
class ServerLogEntitlements
{
    public function forOrganization(Organization $organization): ServerLogEntitlement
    {
        $defaults = (array) config('server_logs.entitlements.defaults', []);
        $plans = (array) config('server_logs.entitlements.plans', []);

        $planKey = (string) ($organization->currentSubscriptionPlan()['key'] ?? 'free');
        $override = is_array($plans[$planKey] ?? null) ? $plans[$planKey] : [];

        return ServerLogEntitlement::fromConfig($planKey, $defaults, $override);
    }
}
