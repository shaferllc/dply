<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Edge;

use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EdgeSiteApiController extends EdgeApiController
{
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $sites = Site::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('edge_backend')
            ->where('edge_backend', '!=', '')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $sites
                ->filter(fn (Site $s): bool => $s->usesEdgeRuntime())
                ->values()
                ->map(fn (Site $s) => $this->siteResource($s)),
        ]);
    }

    public function show(Request $request, string $site): JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        return response()->json(['data' => $this->siteResource($found)]);
    }
}
