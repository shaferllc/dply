<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Servers\ServerMetricsCollector;
use App\Services\Servers\ServerMetricsGuestPushService;
use App\Services\Servers\ServerMetricsRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class GuestMetricsPushController extends Controller
{
    /**
     * POST from server-metrics-snapshot.py on the guest (cron). Uses per-server token in ~/.dply/metrics-callback.env.
     */
    public function store(
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
            ? Carbon::parse($data['captured_at'])
            : now();

        $payload = $collector->normalizePayload($data['metrics']);
        $recorder->storeSnapshot($server->fresh(), $payload, $capturedAt);

        return response()->json(['ok' => true], 202);
    }
}
