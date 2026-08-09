<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Site;
use App\Modules\Serverless\Services\ServerlessQueuePump;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Wakes the queue pump for a serverless function.
 *
 * The first-party Laravel package calls this from a `JobQueued` hook, so a
 * dispatched job starts draining within the round-trip rather than waiting
 * for the next cron edge. This is what takes queue latency on serverless
 * from "up to 60 seconds" to "as fast as the function cold-starts".
 *
 * Authenticated with the site's serverless command secret — the same stable
 * secret the function already carries to prove a `x-dply-run` invocation is
 * really from dply, reused here in the other direction. Compared with
 * hash_equals so a wrong secret costs the same time as a right one.
 *
 * Deliberately cheap and idempotent: it reserves capacity and returns. All
 * the real decisions (how many slots, when to stop) belong to the pump and
 * its slots, so a flood of wakes from a busy app cannot do more than repeat
 * work the ceiling already bounds.
 */
class ServerlessQueueWakeController extends Controller
{
    public function __invoke(Request $request, Site $site, ServerlessQueuePump $pump): JsonResponse
    {
        $secret = trim((string) data_get($site->meta, 'serverless.command_secret', ''));
        $given = trim((string) $request->header('X-Dply-Secret', ''));

        if ($secret === '' || $given === '' || ! hash_equals($secret, $given)) {
            return response()->json(['message' => 'Invalid secret.'], 401);
        }

        $config = $pump->config($site);

        if (! $config['enabled']) {
            return response()->json([
                'woken' => false,
                'reason' => 'Queue processing is disabled for this function.',
            ], 202);
        }

        $opened = $pump->wake($site);

        // 202 either way: "already at capacity" is a success from the
        // caller's perspective — the work will be drained by a running slot,
        // and the app should not retry or treat it as an error.
        return response()->json([
            'woken' => $opened > 0,
            'slots_opened' => $opened,
            'active_slots' => $pump->activeSlots($site),
            'max_concurrency' => $config['max_concurrency'],
        ], 202);
    }
}
