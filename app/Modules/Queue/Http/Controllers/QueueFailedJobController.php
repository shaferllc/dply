<?php

declare(strict_types=1);

namespace App\Modules\Queue\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Queue\Support\QueueRequestContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Failed jobs for a namespace.
 *
 * Laravel's default failed-job provider writes to the app's own database. On a
 * serverless function backed by SQLite that is a per-container `/tmp` file, so
 * a job that exhausts its attempts is recorded and then vanishes with the
 * container — no trace, nothing to retry, nothing to look at. Fixing the queue
 * while leaving that alone would have moved the silent failure one layer up
 * rather than removing it.
 *
 * These endpoints back a `FailedJobProviderInterface` registered by the
 * injected handler, so `queue:failed`, `queue:retry`, and `queue:forget` work
 * against dply instead of a disappearing file.
 *
 * Bearer-authenticated, like the lock endpoints — not SQS operations.
 */
class QueueFailedJobController extends Controller
{
    private const TABLE = 'dply_queue_failed_jobs';

    /** Bound the listing so a large backlog cannot produce a huge response. */
    private const MAX_LIST = 200;

    public function store(Request $request): JsonResponse
    {
        $context = $this->context($request);

        if (! $context instanceof QueueRequestContext) {
            return $this->denied();
        }

        $uuid = trim((string) $request->input('uuid', ''));
        $payload = (string) $request->input('payload', '');

        if ($payload === '') {
            return response()->json(['message' => 'payload is required.'], 400);
        }

        $attributes = [
            'queue' => Str::limit(trim((string) $request->input('queue', 'default')), 128, ''),
            'payload' => $payload,
            'exception' => (string) $request->input('exception', ''),
            'display_name' => $this->displayName($payload),
            'attempts' => (int) $request->input('attempts', 0),
            'failed_at' => DB::raw('now()'),
            'created_at' => DB::raw('now()'),
        ];

        // Keyed on the job uuid so a retry that fails again updates the same
        // row rather than accumulating one per attempt.
        if ($uuid !== '') {
            $this->table()->upsert(
                [array_merge($attributes, [
                    'id' => (string) Str::ulid(),
                    'namespace_id' => $context->namespaceId(),
                    'job_uuid' => Str::limit($uuid, 64, ''),
                ])],
                ['namespace_id', 'job_uuid'],
                ['payload', 'exception', 'attempts', 'failed_at', 'display_name'],
            );
        } else {
            $this->table()->insert(array_merge($attributes, [
                'id' => (string) Str::ulid(),
                'namespace_id' => $context->namespaceId(),
                'job_uuid' => null,
            ]));
        }

        return response()->json(['recorded' => true], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $context = $this->context($request);

        if (! $context instanceof QueueRequestContext) {
            return $this->denied();
        }

        $rows = $this->table()
            ->where('namespace_id', $context->namespaceId())
            ->orderByDesc('failed_at')
            ->limit(max(1, min(self::MAX_LIST, (int) $request->input('limit', 50))))
            ->get();

        return response()->json([
            'failed_jobs' => $rows->map(fn (object $row): array => [
                'id' => $row->job_uuid ?? $row->id,
                'uuid' => $row->job_uuid,
                'connection' => 'dply',
                'queue' => $row->queue,
                'payload' => $row->payload,
                'exception' => $row->exception,
                'failed_at' => $row->failed_at,
            ])->all(),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $context = $this->context($request);

        if (! $context instanceof QueueRequestContext) {
            return $this->denied();
        }

        $row = $this->findScoped($context, $id);

        if ($row === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json([
            'failed_job' => [
                'id' => $row->job_uuid ?? $row->id,
                'uuid' => $row->job_uuid,
                'connection' => 'dply',
                'queue' => $row->queue,
                'payload' => $row->payload,
                'exception' => $row->exception,
                'failed_at' => $row->failed_at,
            ],
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $context = $this->context($request);

        if (! $context instanceof QueueRequestContext) {
            return $this->denied();
        }

        $row = $this->findScoped($context, $id);

        if ($row === null) {
            return response()->json(['forgotten' => false]);
        }

        $this->table()->where('id', $row->id)->delete();

        return response()->json(['forgotten' => true]);
    }

    public function flush(Request $request): JsonResponse
    {
        $context = $this->context($request);

        if (! $context instanceof QueueRequestContext) {
            return $this->denied();
        }

        $query = $this->table()->where('namespace_id', $context->namespaceId());

        $hours = (int) $request->input('hours', 0);
        if ($hours > 0) {
            $query->where('failed_at', '<=', now()->subHours($hours));
        }

        return response()->json(['flushed' => $query->delete()]);
    }

    /**
     * Look up by Laravel's job uuid or by our row id.
     *
     * `queue:retry` and `queue:forget` pass whatever `index()` returned as the
     * id, which is the uuid when there is one — so both have to resolve.
     * Always scoped to the caller's namespace.
     */
    private function findScoped(QueueRequestContext $context, string $id): ?object
    {
        return $this->table()
            ->where('namespace_id', $context->namespaceId())
            ->where(function ($query) use ($id): void {
                $query->where('job_uuid', $id)->orWhere('id', $id);
            })
            ->first();
    }

    /** Best-effort: the envelope carries displayName in plaintext. */
    private function displayName(string $payload): ?string
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded) || ! is_string($decoded['displayName'] ?? null)) {
            return null;
        }

        return Str::limit($decoded['displayName'], 255, '');
    }

    private function table(): Builder
    {
        return DB::connection('dply_queue')->table(self::TABLE);
    }

    private function context(Request $request): ?QueueRequestContext
    {
        $context = $request->attributes->get('queue_context');

        return $context instanceof QueueRequestContext ? $context : null;
    }

    private function denied(): JsonResponse
    {
        return response()->json(['message' => 'Unauthenticated.'], 403);
    }
}
