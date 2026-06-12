<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\WorkerPools\WorkerPoolJobEvent;
use App\Jobs\CollectWorkerPoolHorizonSnapshotJob;
use App\Listeners\ForwardWorkerPoolJobEvent;
use App\Models\WorkerPool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Ingest for per-job Horizon events forwarded (batched) from worker pool boxes
 * (see {@see ForwardWorkerPoolJobEvent}). Authenticated by the
 * pool's `event_token` (Bearer), then re-broadcast over Reverb to the org
 * channel for the live dashboard.
 *
 * Timestamps are stamped HERE with dply's clock (`received_at`) so the UI never
 * does cross-machine clock math — the box's `at` is informational only.
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
        $poolId = (string) $poolModel->id;
        $events = $request->input('events');
        $rows = is_array($events) ? $events : [$request->all()];
        $dropped = (int) $request->input('dropped', 0);
        $receivedAt = now()->timestamp + (now()->milli / 1000);

        $accepted = 0;
        foreach (array_slice($rows, 0, 100) as $row) {
            if (! is_array($row) || ! isset($row['name'])) {
                continue;
            }
            WorkerPoolJobEvent::dispatch($orgId, $poolId, [
                'name' => (string) $row['name'],
                'queue' => (string) ($row['queue'] ?? 'default'),
                'status' => (string) ($row['status'] ?? 'processing'),
                'uuid' => isset($row['uuid']) ? (string) $row['uuid'] : null,
                'received_at' => $receivedAt,
            ]);
            $accepted++;
        }

        // Surface shed events as a single synthetic feed row ("+N dropped").
        if ($dropped > 0) {
            WorkerPoolJobEvent::dispatch($orgId, $poolId, [
                'name' => '+'.$dropped.' more (dropped under load)',
                'queue' => '—',
                'status' => 'dropped',
                'uuid' => null,
                'received_at' => $receivedAt,
            ]);
        }

        // Activity-triggered debounced re-snapshot: real work happened, so refresh
        // the aggregate tiles/buckets/drift — at most once per window, no polling.
        if ($accepted > 0 && Cache::add('wp-snap-debounce:'.$poolId, 1, now()->addSeconds(12))) {
            CollectWorkerPoolHorizonSnapshotJob::dispatch($poolId);
        }

        return response()->json(['accepted' => $accepted]);
    }
}
