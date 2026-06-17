<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Edge;

use App\Models\EdgeAccessLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Polling endpoint that powers `dply edge logs --tail`. The dashboard
 * uses Reverb broadcasting (`edge.access-log` on the `site.{id}`
 * private channel) for true push delivery; the CLI polls this every
 * second because shipping a Pusher-protocol WebSocket client in the
 * CLI package isn't worth the binary bloat for v1.
 *
 * Query params:
 *   ?since=<iso8601>    only return rows newer than this (default: now - 60s)
 *   ?limit=<int>        max rows in one response, capped at 500
 */
class EdgeLogApiController extends EdgeApiController
{
    public function index(Request $request, string $site): JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        $sinceRaw = trim((string) $request->query('since', ''));
        $since = $sinceRaw !== ''
            ? Carbon::parse($sinceRaw)
            : now()->subSeconds(60);
        $limit = min(500, max(1, (int) $request->query('limit', 100)));

        $rows = EdgeAccessLog::query()
            ->where('site_id', $found->id)
            ->where('occurred_at', '>', $since)
            ->orderBy('occurred_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (EdgeAccessLog $row) => [
                'occurred_at' => $row->occurred_at->toIso8601String(),
                'deployment_id' => $row->edge_deployment_id,
                'hostname' => $row->hostname,
                'method' => $row->method,
                'path' => $row->path,
                'status' => $row->status_code,
                'duration_ms' => $row->duration_ms,
                'bytes_egress' => $row->bytes_egress,
                'cache_status' => $row->cache_status,
                'country' => $row->country,
            ]),
            'meta' => [
                'since' => $since->toIso8601String(),
                'count' => $rows->count(),
                'tail_cursor' => $rows->last()?->occurred_at?->toIso8601String() ?? $since->toIso8601String(),
            ],
        ]);
    }
}
