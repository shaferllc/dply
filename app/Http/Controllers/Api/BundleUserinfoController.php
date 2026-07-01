<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OIDC-style userinfo for "Log in with dply". After a product (tracely/Lookout)
 * exchanges the Passport authorization code, it calls this with the access token
 * to learn WHO logged in and WHICH orgs they belong to — the multi-workspace
 * claim set (Q7): the products render a workspace per bundle-entitled org.
 *
 * Returns the user (`sub`) plus every org they're a member of with role +
 * `bundle_entitled` (from the single-source predicate), so a product provisions
 * JIT for entitled orgs and shows nothing for the rest.
 *
 * Reached only under the Passport `auth:api` guard (registered by
 * BundleSsoServiceProvider, so it's dark until Passport is installed).
 * See docs/adr/bundled-products-sso.md.
 */
final class BundleUserinfoController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $orgs = $user->organizations()->get()->map(fn (Organization $org): array => [
            'id' => (string) $org->id,
            'name' => (string) $org->name,
            'role' => $org->getRelationValue('pivot')?->role,
            'bundle_entitled' => $org->qualifiesForBundledProducts(),
        ])->values();

        $current = $user->currentOrganization();

        return response()->json([
            'sub' => (string) $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'org_id' => $current !== null ? (string) $current->id : null,
            'orgs' => $orgs,
        ]);
    }
}
