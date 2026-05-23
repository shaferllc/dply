<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EdgeAccessLog;
use App\Models\Site;
use App\Services\Edge\EdgePerformanceHourlyRollup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Receives per-request Edge Worker access records (Analytics Engine companion ingest).
 */
class EdgeLogIngestController extends Controller
{
    private const MAX_PATH = 2048;

    public function __construct(
        private readonly EdgePerformanceHourlyRollup $rollup,
    ) {}

    public function __invoke(Request $request, Site $site): JsonResponse
    {
        if (! $site->usesEdgeRuntime()) {
            return response()->json(['message' => 'Not an Edge site.'], 404);
        }

        $key = trim((string) config('edge.log_ingest.key', ''));
        $signature = (string) $request->header('X-Dply-Signature', '');
        $expected = $key !== ''
            ? hash_hmac('sha256', $site->id.'.'.$request->getContent(), $key)
            : '';

        if ($key === '' || $signature === '' || ! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $status = (int) $request->input('status', 0);
        $durationMs = max(0, (int) $request->input('duration_ms', 0));
        $bytes = max(0, (int) $request->input('bytes_egress', 0));
        $occurredAt = Carbon::parse((string) $request->input('occurred_at', now()->toIso8601String()));

        EdgeAccessLog::query()->create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'edge_deployment_id' => Str::limit((string) $request->input('deployment_id', ''), 26, '') ?: null,
            'hostname' => strtolower(Str::limit((string) $request->input('hostname', ''), 255, '')),
            'method' => strtoupper(Str::limit((string) $request->input('method', 'GET'), 12, '')),
            'path' => Str::limit('/'.ltrim((string) $request->input('path', '/'), '/'), self::MAX_PATH, ''),
            'status_code' => $status > 0 ? $status : null,
            'duration_ms' => $durationMs,
            'bytes_egress' => $bytes,
            'country' => Str::limit((string) $request->input('country', ''), 8, '') ?: null,
            'cache_status' => Str::limit((string) $request->input('cache_status', ''), 32, '') ?: null,
            'referrer' => Str::limit((string) $request->input('referrer', ''), 2048, '') ?: null,
            'user_agent' => Str::limit((string) $request->input('user_agent', ''), 512, '') ?: null,
            'source' => 'worker',
            'occurred_at' => $occurredAt,
        ]);

        $this->rollup->record(
            $site,
            $occurredAt,
            $status,
            $durationMs,
            $bytes,
            (string) $request->input('cache_status', ''),
        );

        return response()->json(['message' => 'Recorded.'], 202);
    }
}
