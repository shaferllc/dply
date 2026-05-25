<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Edge;

use App\Services\Edge\EdgeCachePurger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EdgeCacheApiController extends EdgeApiController
{
    public function purge(Request $request, string $site): JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        try {
            $data = $request->validate([
                'tag' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        $result = app(EdgeCachePurger::class)->purgeByTag($found->fresh(), (string) $data['tag']);

        return response()->json([
            'data' => [
                'ok' => $result['ok'] ?? false,
                'purged_keys' => $result['purged_keys'] ?? [],
                'message' => $result['message'] ?? null,
            ],
        ], $result['ok'] ?? false ? 200 : 422);
    }
}
