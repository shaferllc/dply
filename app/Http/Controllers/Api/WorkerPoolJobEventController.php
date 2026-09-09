<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\WorkerPools\WorkerPoolJobEvent;
use App\Jobs\CollectWorkerPoolHorizonSnapshotJob;
use App\Listeners\ForwardWorkerPoolJobEvent;
use App\Models\Site;
use App\Models\SiteQueueJobRun;
use App\Models\WorkerPool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        // The site whose app this pool runs — history is filed against it.
        // site_worker_pool is the explicit attachment; the source server's site
        // is the fallback for a pool cloned from a site that never attached it.
        $appSiteId = DB::table('site_worker_pool')->where('worker_pool_id', $poolId)->value('site_id')
            ?? Site::query()->where('server_id', $poolModel->source_server_id)->value('id');
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
                'at' => $receivedAt,
            ]);
            // Same event, second destination: the pool runs this SITE's app, so
            // a job it ran belongs in that site's history. Broadcasting alone
            // made pool work invisible the moment the live feed scrolled away,
            // and split "which jobs ran" across two places that each had half
            // the answer. Terminal states only — 'processing' is a live-feed
            // concern, not history.
            if ($appSiteId !== null && in_array((string) ($row['status'] ?? ''), ['processed', 'failed'], true)) {
                SiteQueueJobRun::query()->create([
                    'site_id' => $appSiteId,
                    'job_id' => isset($row['uuid']) ? (string) $row['uuid'] : null,
                    'name' => class_basename(trim((string) $row['name'])) ?: (string) $row['name'],
                    'queue' => (string) ($row['queue'] ?? 'default'),
                    'status' => (string) $row['status'],
                    'source' => SiteQueueJobRun::SOURCE_POOL,
                    'worker_pool_id' => $poolId,
                    'duration_ms' => is_numeric($row['duration_ms'] ?? null) ? max(0, (int) $row['duration_ms']) : null,
                    'ran_at' => now(),
                ]);
            }

            $accepted++;
        }

        // Surface shed events as a single synthetic feed row ("+N dropped").
        if ($dropped > 0) {
            WorkerPoolJobEvent::dispatch($orgId, $poolId, [
                'name' => '+'.$dropped.' more (dropped under load)',
                'queue' => '—',
                'status' => 'dropped',
                'uuid' => null,
                'at' => $receivedAt,
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
