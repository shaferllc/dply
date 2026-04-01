<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerMetricIngestEvent;
use App\Services\Servers\ServerMetricsCollector;
use App\Services\Servers\ServerMetricsGuestPushService;
use App\Services\Servers\ServerMetricsRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MetricsController extends Controller
{
    public function store(
        Request $request,
        ServerMetricsGuestPushService $push,
        ServerMetricsCollector $collector,
        ServerMetricsRecorder $recorder,
    ): JsonResponse {
        if ($request->bearerToken() !== null || $request->has('snapshot_id')) {
            return $this->storeIngest($request);
        }

        return $this->storeGuestPush($request, $push, $collector, $recorder);
    }

    protected function storeIngest(Request $request): JsonResponse
    {
        $token = (string) config('server_metrics.ingest.token', '');
        if ($token === '') {
            return response()->json(['message' => 'Metrics ingest is not configured (set DPLY_METRICS_INGEST_TOKEN).'], 503);
        }

        $bearer = (string) $request->bearerToken();
        if ($bearer === '' || ! hash_equals($token, $bearer)) {
            return response()->json(['message' => 'Invalid metrics ingest token.'], 401);
        }

        $data = $request->validate([
            'snapshot_id' => ['required', 'integer', 'min:1'],
            'server_id' => ['required', 'string', 'max:64'],
            'organization_id' => ['required', 'string', 'max:64'],
            'server_name' => ['nullable', 'string', 'max:255'],
            'captured_at' => ['required', 'date'],
            'metrics' => ['required', 'array'],
        ]);

        ServerMetricIngestEvent::query()->create([
            'source_snapshot_id' => $data['snapshot_id'],
            'organization_id' => $data['organization_id'],
            'server_id' => $data['server_id'],
            'server_name' => $data['server_name'] ?? null,
            'captured_at' => $data['captured_at'],
            'metrics' => $data['metrics'],
        ]);

        return response()->json(['ok' => true], 202);
    }

    protected function storeGuestPush(
        Request $request,
        ServerMetricsGuestPushService $push,
        ServerMetricsCollector $collector,
        ServerMetricsRecorder $recorder,
    ): JsonResponse {
        if (! $push->isEnabled()) {
            return response()->json(['message' => 'Guest metrics push is disabled.'], 503);
        }

        $data = $request->validate([
            'server_id' => ['required', 'string', 'max:64'],
            'token' => ['required', 'string'],
            'metrics' => ['required', 'array'],
            'captured_at' => ['nullable', 'date'],
        ]);

        $server = Server::query()->find($data['server_id']);
        if ($server === null) {
            return response()->json(['message' => 'Unknown server.'], 404);
        }

        if (! $push->verifyToken($server, $data['token'])) {
            return response()->json(['message' => 'Invalid token.'], 403);
        }

        $capturedAt = isset($data['captured_at'])
            ? $this->parseGuestCapturedAt($server, (string) $data['captured_at'])
            : now();

        $payload = $collector->normalizePayload($data['metrics']);
        $recorder->storeSnapshot($server->fresh(), $payload, $capturedAt);

        $meta = $server->meta ?? [];
        $meta['monitoring_guest_push_last_sample_at'] = $capturedAt->toIso8601String();
        $server->forceFill(['meta' => $meta])->saveQuietly();

        return response()->json(['ok' => true], 202);
    }

    protected function parseGuestCapturedAt(Server $server, string $rawCapturedAt): Carbon
    {
        if ($this->capturedAtHasExplicitTimezone($rawCapturedAt)) {
            return Carbon::parse($rawCapturedAt)->utc();
        }

        $serverTimezone = trim((string) (($server->meta ?? [])['timezone'] ?? '')) ?: 'UTC';

        return Carbon::parse($rawCapturedAt, $serverTimezone)->utc();
    }

    protected function capturedAtHasExplicitTimezone(string $rawCapturedAt): bool
    {
        return preg_match('/(?:Z|[+-]\d{2}:\d{2}|[+-]\d{4}| [A-Z]{2,5})$/i', trim($rawCapturedAt)) === 1;
    }
}
