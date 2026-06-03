<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\WorkerPools\WorkerPoolJobEvent;
use App\Models\WorkerPool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ingest endpoint for per-job Horizon events forwarded from worker pool boxes
 * (see {@see \App\Listeners\ForwardWorkerPoolJobEvent}). Authenticated by the
 * pool's `event_token` (Bearer), then re-broadcast over Reverb to the org's
 * private channel so the pool dashboard updates live.
 *
 * Accepts a single event or a small batch (`events: [...]`) so the box agent
 * can coalesce bursts into one request.
 */
class WorkerPoolJobEventController
{
    public function store(Request $request, string $pool): JsonResponse
    {
        $poolModel = WorkerPool::query()->find($pool);
        if (! $poolModel instanceof WorkerPool) {
            return response()->json(['message' => 'Pool not found.'], 404);
        }

        $token = (string) ($poolModel->meta['event_token'] ?? '');
        $presented = (string) $request->bearerToken();
        if ($token === '' || $presented === '' || ! hash_equals($token, $presented)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $orgId = (string) $poolModel->organization_id;
        $events = $request->input('events');
        $rows = is_array($events) ? $events : [$request->all()];

        $accepted = 0;
        foreach (array_slice($rows, 0, 100) as $row) {
            if (! is_array($row) || ! isset($row['name'])) {
                continue;
            }
            WorkerPoolJobEvent::dispatch($orgId, (string) $poolModel->id, [
                'name' => (string) $row['name'],
                'queue' => (string) ($row['queue'] ?? 'default'),
                'status' => (string) ($row['status'] ?? 'processing'),
                'uuid' => isset($row['uuid']) ? (string) $row['uuid'] : null,
                'at' => (float) ($row['at'] ?? microtime(true)),
            ]);
            $accepted++;
        }

        return response()->json(['accepted' => $accepted]);
    }
}
