<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Site;
use App\Models\SiteQueueJobRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ingest for the in-app queue agent.
 *
 * Authed with a per-site token compared via `hash_equals`, the same shape
 * {@see WorkerPoolJobEventController} uses for box-to-dply reporting: the agent
 * runs inside a customer's application, so it holds a token scoped to one site
 * and nothing else.
 *
 * `ran_at` is stamped with DPLY's clock. The agent sends its own `at`, but that
 * is the box's clock, and history that mixes two clocks produces jobs that
 * appear to finish before they started.
 */
class SiteQueueEventController
{
    /** A worker flushes its buffer on shutdown; more than this in one request is a bug or an attack. */
    private const MAX_EVENTS = 100;

    public function store(Request $request, string $site): JsonResponse
    {
        $siteModel = Site::query()->find($site);

        if (! $siteModel instanceof Site) {
            return response()->json(['message' => 'Site not found.'], 404);
        }

        $token = (string) data_get($siteModel->meta, 'queue_insights.token', '');
        $presented = (string) $request->bearerToken();

        if ($token === '' || $presented === '' || ! hash_equals($token, $presented)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $events = $request->input('events');
        $rows = is_array($events) ? $events : [];
        $accepted = 0;
        $now = now();

        foreach (array_slice($rows, 0, self::MAX_EVENTS) as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null)) {
                continue;
            }

            $status = match ($row['event'] ?? null) {
                'processed' => SiteQueueJobRun::STATUS_PROCESSED,
                'failed' => SiteQueueJobRun::STATUS_FAILED,
                // 'queued' is dropped on purpose: a waiting job is already
                // visible in the store, and storing both doubles the write
                // volume to say the same thing twice.
                default => null,
            };

            if ($status === null) {
                continue;
            }

            $jobId = $this->str($row['id'] ?? null, 191);

            // A run dply dispatched is already in the table as queued/taken,
            // keyed by the id the worker reports here. Updating it closes that
            // row instead of filing a second one for the same execution.
            $opened = $jobId === null ? null : SiteQueueJobRun::query()
                ->where('site_id', $siteModel->id)
                ->where('job_id', $jobId)
                ->whereIn('status', [SiteQueueJobRun::STATUS_QUEUED, SiteQueueJobRun::STATUS_TAKEN])
                ->latest('ran_at')
                ->first();

            if ($opened !== null) {
                $opened->forceFill([
                    'status' => $status,
                    'duration_ms' => is_numeric($row['duration_ms'] ?? null) ? max(0, (int) $row['duration_ms']) : $opened->duration_ms,
                    'attempts' => is_numeric($row['attempts'] ?? null) ? max(0, (int) $row['attempts']) : $opened->attempts,
                    'exception' => $this->str($row['exception'] ?? null, 191),
                    'message' => $this->str($row['message'] ?? null, 500),
                ])->save();

                $accepted++;

                continue;
            }

            SiteQueueJobRun::query()->create([
                'site_id' => $siteModel->id,
                'job_id' => $jobId,
                'name' => $this->shortName((string) $row['name']),
                'queue' => $this->str($row['queue'] ?? null, 191),
                'connection' => $this->str($row['connection'] ?? null, 191),
                'status' => $status,
                'duration_ms' => is_numeric($row['duration_ms'] ?? null) ? max(0, (int) $row['duration_ms']) : null,
                'attempts' => is_numeric($row['attempts'] ?? null) ? max(0, (int) $row['attempts']) : null,
                'exception' => $this->str($row['exception'] ?? null, 191),
                'message' => $this->str($row['message'] ?? null, 500),
                'ran_at' => $now,
            ]);

            $accepted++;
        }

        return response()->json(['accepted' => $accepted]);
    }

    /** Class name without its namespace — what identifies a job in a list. */
    private function shortName(string $name): string
    {
        return Str::limit(class_basename(trim($name)) ?: trim($name), 190, '');
    }

    private function str(mixed $value, int $limit): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === null || $value === '' ? null : Str::limit($value, $limit, '');
    }
}
