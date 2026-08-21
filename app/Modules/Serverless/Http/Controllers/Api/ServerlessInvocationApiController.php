<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Http\Controllers\Api;

use App\Modules\Serverless\Models\FunctionInvocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Invocation history for `dply serverless invocations` and
 * `dply serverless errors`.
 *
 * This table — not DigitalOcean's activations API, which never returns
 * anything (see {@see FunctionInvocation}) — is the source of truth for what
 * has hit a function, so it is also the only place a failed invocation can be
 * read back from. `?failed=1` is therefore the serverless error feed.
 *
 * Query params:
 *   ?since=<iso8601>   only rows newer than this (tail cursor)
 *   ?failed=1          only settled failures
 *   ?source=web|tick|test
 *   ?limit=<int>       capped at 200
 */
class ServerlessInvocationApiController extends ServerlessApiController
{
    public function index(Request $request, string $site): JsonResponse
    {
        $found = $this->findFunctionSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        $query = FunctionInvocation::query()
            ->where('site_id', $found->id);

        $sinceRaw = trim((string) $request->query('since', ''));
        $since = $sinceRaw !== '' ? Carbon::parse($sinceRaw) : null;
        if ($since !== null) {
            $query->where('created_at', '>', $since);
        }

        // Failures are only meaningful once settled — a pending row has not
        // failed, it just has no outcome yet.
        if ($request->boolean('failed')) {
            $query->settled()->where('success', false);
        }

        $source = trim((string) $request->query('source', ''));
        if ($source !== '') {
            $query->where('source', $source);
        }

        $limit = $this->limit($request, 50, 200);

        // Ascending when tailing so the CLI can print rows in order and take
        // the last created_at as its next cursor; newest-first otherwise so a
        // bare listing shows the most recent activity.
        $rows = $since !== null
            ? $query->orderBy('created_at')->limit($limit)->get()
            : $query->orderByDesc('created_at')->limit($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (FunctionInvocation $row) => $this->summary($row))->values(),
            'meta' => [
                'count' => $rows->count(),
                'tail_cursor' => $this->tailCursor($rows->max('created_at'), $since),
            ],
        ]);
    }

    public function show(Request $request, string $site, string $invocation): JsonResponse
    {
        $found = $this->findFunctionSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        $row = FunctionInvocation::query()
            ->where('site_id', $found->id)
            ->find($invocation);

        if ($row === null) {
            return $this->notFound('Invocation not found.');
        }

        return response()->json([
            'data' => $this->summary($row) + [
                // logLines() filters the OpenWhisk end-of-activation sentinel
                // pair, which would otherwise be most of a quiet function's log.
                'log_lines' => $row->logLines(),
                'result_excerpt' => $row->result_excerpt,
                'context' => $row->context,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(FunctionInvocation $row): array
    {
        return [
            'id' => (string) $row->id,
            'activation_id' => $row->activation_id,
            'source' => $row->source,
            'state' => $row->state,
            'success' => $row->success,
            'status_code' => $row->status_code,
            'method' => $row->method,
            'path' => $row->path,
            'task' => $row->task,
            'duration_ms' => $row->duration_ms,
            'wait_time_ms' => $row->wait_time_ms,
            'init_time_ms' => $row->init_time_ms,
            'cold' => $row->cold,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    /**
     * Advance the cursor only when rows came back; an empty poll must return
     * the caller's own cursor or the tail would skip whatever lands next.
     */
    private function tailCursor(mixed $newest, ?Carbon $since): ?string
    {
        if ($newest instanceof Carbon) {
            return $newest->toIso8601String();
        }

        return $since?->toIso8601String();
    }
}
