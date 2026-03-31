<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServerMetricIngestEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetricsIngestController extends Controller
{
    /**
     * Accepts metric snapshots from BYO app workers ({@see PushServerMetricSnapshotToIngestJob}).
     */
    public function store(Request $request): JsonResponse
    {
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
}
