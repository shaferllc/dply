<?php

declare(strict_types=1);

namespace App\Modules\Queue\Support;

use Illuminate\Http\Request;

/**
 * Which SQS action a request is asking for, and whether it is a poll.
 *
 * Shared by the controller (which dispatches on it) and the rate limiter
 * (which buckets on it). Two copies of this would drift, and the failure mode
 * of drift is a request throttled against the wrong allowance — invisible
 * until someone's drain stalls.
 */
final class QueueAction
{
    /**
     * Actions that only READ the queue and change nothing when they come back
     * empty. These get their own allowance: a worker polling an empty queue
     * costs one indexed query, and charging it against the same bucket as real
     * work lets an idle fleet starve the burst it is waiting for.
     */
    private const POLLING = ['ReceiveMessage'];

    public static function of(Request $request): string
    {
        $target = (string) $request->header('X-Amz-Target', '');

        if ($target !== '' && str_contains($target, '.')) {
            return substr($target, strrpos($target, '.') + 1);
        }

        // Query-protocol clients (and curl) send Action in the body. Parsed
        // only when the header is absent, so the SDK path — which is every
        // Laravel client — never pays for the decode.
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? (string) ($decoded['Action'] ?? '') : '';
    }

    public static function isPoll(Request $request): bool
    {
        return in_array(self::of($request), self::POLLING, true);
    }
}
