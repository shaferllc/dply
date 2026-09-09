<?php

declare(strict_types=1);

namespace App\Support\Sites;

/**
 * One waiting job, read straight out of the driver.
 *
 * Laravel writes the SAME JSON envelope whichever store backs the queue — the
 * `jobs` table's `payload` column and a Redis list entry are byte-identical in
 * shape. That is why a job list needs no package and no app changes for the two
 * drivers most sites actually run: the store already holds the class name, the
 * attempt count and the enqueue time.
 *
 * What it does NOT hold is anything about execution — duration, memory, or that
 * a job succeeded — because the row is deleted the moment it does. Those need
 * queue-event instrumentation inside the app, and this class deliberately does
 * not pretend otherwise.
 */
final class QueueJobPayload
{
    public function __construct(
        public readonly string $name,
        public readonly int $attempts,
        public readonly ?int $waitingSeconds,
        public readonly ?string $uuid,
    ) {}

    /**
     * @param  int|null  $now  Box clock at read time. Wait is computed against
     *                         the box, never against dply's clock — a few
     *                         seconds of drift renders as a negative age and
     *                         reads as a bug.
     */
    public static function fromJson(string $json, ?int $now = null): ?self
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            return null;
        }

        $name = $data['displayName'] ?? $data['job'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        $pushedAt = $data['pushedAt'] ?? null;
        $waiting = null;

        if (is_numeric($pushedAt) && $now !== null) {
            // Clamp at zero rather than showing "-3s" when the clocks disagree.
            $waiting = max(0, $now - (int) $pushedAt);
        }

        return new self(
            self::shorten(trim($name)),
            // Laravel counts attempts from 0 while the job waits; a job that has
            // never run has made one attempt from the operator's point of view.
            max(1, (int) ($data['attempts'] ?? 0) + 1),
            $waiting,
            is_string($data['uuid'] ?? null) ? $data['uuid'] : null,
        );
    }

    /** The class name without its namespace, which is what identifies a job in a list. */
    private static function shorten(string $name): string
    {
        $short = strrchr($name, '\\');

        return $short === false ? $name : ltrim($short, '\\');
    }
}
