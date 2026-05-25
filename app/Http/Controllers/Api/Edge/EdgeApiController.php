<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Edge;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared lookup helpers for the public /api/v1/edge/* surface. Every
 * Edge API controller extends this so org scoping + the Edge-specific
 * 404 ("site exists but is not an Edge site") stay consistent.
 *
 * Payload shaping lives in {@see \App\Http\Resources\Edge\*Resource}
 * classes — the base no longer carries inline `toArray()` builders.
 */
abstract class EdgeApiController extends Controller
{
    protected function organization(Request $request): Organization
    {
        $organization = $request->attributes->get('api_organization');
        if (! $organization instanceof Organization) {
            abort(401);
        }

        return $organization;
    }

    /**
     * Look up an Edge site by ID within the request's organization.
     * Returns null when the site does not exist, belongs to another
     * org, or is not an Edge site — callers turn that into a 404.
     */
    protected function findEdgeSite(Request $request, string $siteId): ?Site
    {
        $organization = $this->organization($request);

        $site = Site::query()
            ->where('organization_id', $organization->id)
            ->find($siteId);

        if ($site === null || ! $site->usesEdgeRuntime()) {
            return null;
        }

        return $site;
    }

    protected function notFound(string $message = 'Edge site not found.'): JsonResponse
    {
        return response()->json(['message' => $message], 404);
    }

}
