<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared lookup helpers for the public /api/v1/serverless/* surface. Mirrors
 * {@see \App\Modules\Edge\Http\Controllers\Api\EdgeApiController} so org
 * scoping and the surface-specific 404 ("site exists but is not a function")
 * stay consistent across the two managed surfaces.
 */
abstract class ServerlessApiController extends Controller
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
     * Look up a serverless function Site by ID within the request's
     * organization. Returns null when the site does not exist, belongs to
     * another org, or is not a function — callers turn that into a 404.
     */
    protected function findFunctionSite(Request $request, string $siteId): ?Site
    {
        $site = Site::query()
            ->where('organization_id', $this->organization($request)->id)
            ->find($siteId);

        if ($site === null || ! $site->usesFunctionsRuntime()) {
            return null;
        }

        return $site;
    }

    protected function notFound(string $message = 'Serverless function not found.'): JsonResponse
    {
        return response()->json(['message' => $message], 404);
    }

    /**
     * Clamp a `?limit=` query param into a sane page size.
     */
    protected function limit(Request $request, int $default, int $max): int
    {
        return min($max, max(1, (int) $request->query('limit', (string) $default)));
    }
}
