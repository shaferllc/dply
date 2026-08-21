<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Http\Controllers\Api;

use App\Models\AppLogRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Polling endpoint for `dply serverless logs --follow` — application log
 * records shipped in by the dply Realtime drain.
 *
 * Same contract as {@see \App\Modules\Edge\Http\Controllers\Api\EdgeLogApiController}:
 * the dashboard gets true push over Reverb, the CLI polls this, because
 * shipping a Pusher-protocol WebSocket client in the CLI package is not worth
 * the bloat.
 *
 * These are the function's *application* logs. Per-invocation platform output
 * (stdout/stderr captured from the activation) lives on the invocation itself —
 * see {@see ServerlessInvocationApiController::show()}.
 *
 * Query params:
 *   ?since=<iso8601>   only rows newer than this (default: now - 60s)
 *   ?level=error,warn  filter by log level (comma-separated)
 *   ?limit=<int>       capped at 500
 */
class ServerlessLogApiController extends ServerlessApiController
{
    public function index(Request $request, string $site): JsonResponse
    {
        $found = $this->findFunctionSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        $sinceRaw = trim((string) $request->query('since', ''));
        $since = $sinceRaw !== '' ? Carbon::parse($sinceRaw) : now()->subSeconds(60);

        $query = AppLogRecord::query()
            ->where('site_id', $found->id)
            ->where('created_at', '>', $since);

        $levels = $this->levels($request);
        if ($levels !== []) {
            $query->whereIn('level', $levels);
        }

        $rows = $query
            ->orderBy('created_at')
            ->limit($this->limit($request, 100, 500))
            ->get();

        return response()->json([
            'data' => $rows->map(fn (AppLogRecord $row) => [
                'id' => (string) $row->id,
                'level' => $row->level,
                'channel' => $row->channel,
                'message' => $row->message,
                'context' => $row->context,
                'logged_at' => $row->logged_at?->toIso8601String(),
                'created_at' => $row->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'since' => $since->toIso8601String(),
                'count' => $rows->count(),
                // created_at is the tail key, not logged_at — logged_at comes
                // from the sender's clock and can arrive out of order.
                'tail_cursor' => $rows->last()?->created_at?->toIso8601String() ?? $since->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    private function levels(Request $request): array
    {
        $raw = trim((string) $request->query('level', ''));
        if ($raw === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn (string $level): string => strtolower(trim($level)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
