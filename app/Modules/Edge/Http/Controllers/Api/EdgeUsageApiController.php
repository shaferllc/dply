<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers\Api;

use App\Services\Billing\EdgeSiteTrafficAnalytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EdgeUsageApiController extends EdgeApiController
{
    public function show(Request $request, string $site): JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        $days = min(90, max(1, (int) $request->query('days', 30)));
        $usage = app(EdgeSiteTrafficAnalytics::class)->forSite($found, $days);

        return response()->json([
            'data' => $usage ?? [
                'message' => 'Usage not yet available — wait for the first nightly rollup.',
            ],
        ]);
    }
}
