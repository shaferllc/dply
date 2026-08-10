<?php

declare(strict_types=1);

namespace App\Modules\Queue\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Queue\Services\PostgresQueueLockStore;
use App\Modules\Queue\Support\QueueRequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared atomic locks for a namespace.
 *
 * Not part of the SQS protocol — these back Laravel's cache-based
 * `LockProvider`, which is what `ShouldBeUnique`, `WithoutOverlapping`, and
 * `RateLimited` actually use. On a function the default cache store is
 * per-invocation, so all three silently no-op; this gives them somewhere real
 * to coordinate.
 *
 * Bearer-authenticated rather than SigV4: there is no compatibility contract
 * to honour here, and requiring a signature would mean shipping a signer into
 * every customer app.
 */
class QueueLockController extends Controller
{
    public function __construct(private readonly PostgresQueueLockStore $locks) {}

    public function acquire(Request $request): JsonResponse
    {
        $context = $this->context($request);

        if (! $context instanceof QueueRequestContext) {
            return $this->denied();
        }

        $name = trim((string) $request->input('name', ''));
        $owner = trim((string) $request->input('owner', ''));

        if ($name === '' || $owner === '') {
            return response()->json(['message' => 'name and owner are required.'], 400);
        }

        return response()->json([
            'acquired' => $this->locks->acquire(
                $context->namespace,
                $name,
                $owner,
                (int) $request->input('seconds', 60),
            ),
        ]);
    }

    public function release(Request $request): JsonResponse
    {
        $context = $this->context($request);

        if (! $context instanceof QueueRequestContext) {
            return $this->denied();
        }

        $name = trim((string) $request->input('name', ''));
        $owner = trim((string) $request->input('owner', ''));

        if ($name === '' || $owner === '') {
            return response()->json(['message' => 'name and owner are required.'], 400);
        }

        // False here is not an error: it means this owner no longer holds the
        // lock, which is exactly what the fencing check is for.
        return response()->json([
            'released' => $this->locks->release($context->namespace, $name, $owner),
        ]);
    }

    public function forceRelease(Request $request): JsonResponse
    {
        $context = $this->context($request);

        if (! $context instanceof QueueRequestContext) {
            return $this->denied();
        }

        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return response()->json(['message' => 'name is required.'], 400);
        }

        $this->locks->forceRelease($context->namespace, $name);

        return response()->json(['released' => true]);
    }

    public function owner(Request $request): JsonResponse
    {
        $context = $this->context($request);

        if (! $context instanceof QueueRequestContext) {
            return $this->denied();
        }

        return response()->json([
            'owner' => $this->locks->owner($context->namespace, trim((string) $request->input('name', ''))),
        ]);
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
