<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;

/**
 * The pull entitlements API — the correctness backstop for the bundled-products
 * perk (Q6). tracely/Lookout call this to reconcile their local workspace status
 * against dply's live entitlement, healing any missed `bundle.*` webhook.
 * Reads the ONE source of truth, {@see Organization::qualifiesForBundledProducts()},
 * so it can never disagree with the push path. See docs/adr/bundled-products-sso.md.
 */
final class BundleEntitlementsController extends Controller
{
    public function show(Organization $organization): JsonResponse
    {
        return response()->json([
            'org' => [
                'id' => (string) $organization->id,
                'name' => (string) $organization->name,
            ],
            'bundle_entitled' => $organization->qualifiesForBundledProducts(),
            'plan' => $organization->planTierLabel(),
            'checked_at' => now()->toIso8601String(),
        ]);
    }
}
