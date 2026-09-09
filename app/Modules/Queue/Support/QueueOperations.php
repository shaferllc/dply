<?php

declare(strict_types=1);

namespace App\Modules\Queue\Support;

/**
 * What counts as a billable queue operation, and how many one call is.
 *
 * A job's normal life is three operations — dispatched, started, deleted —
 * plus one per visibility extension while it runs and one more per attempt if
 * it is released back. Charging per API *request* instead would bill the
 * customer for dply's polling design and reward us for never improving it
 * (the same argument QueueUsageMeter makes for counting jobs, not requests).
 *
 * Payload-bearing operations are measured in 64 KiB chunks: a dispatch that
 * carries a megabyte costs the store sixteen times what a small one does, and
 * pricing it identically would make the largest payloads the cheapest per
 * byte to send.
 */
final class QueueOperations
{
    public const CHUNK_BYTES = 65_536;

    /** Enqueue. Payload-bearing. */
    public const DISPATCH = 'dispatch';

    /** Delivery to a worker. Payload-bearing. */
    public const START = 'start';

    /** Completion — the ack that deletes the row. */
    public const DELETE = 'delete';

    /** A lease extended while the job keeps running. */
    public const EXTEND = 'extend';

    /** Released back for another attempt, or recorded as failed. */
    public const RETRY = 'retry';

    /**
     * Operations for one payload-bearing event.
     *
     * Always at least one: a zero-byte body is still an operation, and
     * rounding it to nothing would make an empty dispatch free.
     */
    public static function forPayload(string $payload): int
    {
        return max(1, (int) ceil(strlen($payload) / self::CHUNK_BYTES));
    }

    /**
     * @param  list<string>  $payloads
     */
    public static function forPayloads(array $payloads): int
    {
        $total = 0;

        foreach ($payloads as $payload) {
            $total += self::forPayload((string) $payload);
        }

        return $total;
    }
}
