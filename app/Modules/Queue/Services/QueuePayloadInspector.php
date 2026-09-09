<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services;

/**
 * Reads what dply needs out of a Laravel job envelope at push time.
 *
 * Laravel serializes the envelope with `uuid`, `displayName`, `maxTries`,
 * `timeout`, `retryUntil`, and `data.batchId` in **plaintext** — only
 * `data.command` is encrypted, and then only for `ShouldBeEncrypted` jobs. So
 * one JSON decode at push gives us everything below without ever touching the
 * customer's payload contents.
 *
 * The valuable field is `timeout`. Laravel's rule is `retry_after > timeout`,
 * and violating it is the single most common queue misconfiguration there is:
 * the job runs past `retry_after`, another worker re-reserves it, and it
 * processes twice while the first worker is still going. Because dply owns
 * the lease, it can clamp it against the job's own declared timeout — making
 * that failure **unrepresentable** on this queue rather than merely
 * documented.
 *
 * Everything here is best-effort. A raw string payload, a non-Laravel
 * producer, or a malformed envelope yields nulls and the queue works exactly
 * as it would have anyway.
 */
final class QueuePayloadInspector
{
    /**
     * @return array{
     *     job_uuid: ?string,
     *     job_timeout: ?int,
     *     job_max_tries: ?int,
     *     batch_id: ?string,
     *     display_name: ?string
     * }
     */
    public function inspect(string $payload): array
    {
        $blank = [
            'job_uuid' => null,
            'job_timeout' => null,
            'job_max_tries' => null,
            'batch_id' => null,
            'display_name' => null,
            'group_key' => null,
        ];

        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return $blank;
        }

        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        return [
            'job_uuid' => $this->string($decoded['uuid'] ?? null, 64),
            'job_timeout' => $this->positiveInt($decoded['timeout'] ?? null),
            'job_max_tries' => $this->positiveInt($decoded['maxTries'] ?? null),
            'batch_id' => $this->string($data['batchId'] ?? null, 64),
            'display_name' => $this->string($decoded['displayName'] ?? null, 255),
            // Per-group FIFO. Read from the job's own data first — a job that
            // knows its ordering key sets it — then the envelope, which is
            // where an SQS-compatible client puts MessageGroupId. Absent means
            // ungrouped, which is today's fully-concurrent behaviour.
            'group_key' => $this->string(
                $data['groupKey'] ?? $data['messageGroupId'] ?? $decoded['groupKey'] ?? $decoded['messageGroupId'] ?? null,
                128,
            ),
        ];
    }

    /**
     * How long a claim of this job should stay invisible.
     *
     * Takes the larger of what the consumer asked for and what the job itself
     * says it needs, so a worker that under-requests cannot cause its own job
     * to be re-delivered mid-run. Then bounded by the platform maximum so a
     * client cannot park a job for a day by asking nicely.
     *
     * The grace allowance covers the gap between a job finishing and its ack
     * landing — without it, a job that runs for exactly its timeout would race
     * its own lease.
     */
    public function leaseSeconds(?int $jobTimeout, ?int $requestedVisibility = null): int
    {
        $default = (int) config('queue_service.reservation.default_visibility_seconds', 60);
        $grace = (int) config('queue_service.reservation.lease_grace_seconds', 15);
        $max = (int) config('queue_service.reservation.max_visibility_seconds', 43200);

        $requested = $requestedVisibility !== null && $requestedVisibility > 0
            ? $requestedVisibility
            : $default;

        $needed = $jobTimeout !== null && $jobTimeout > 0
            ? $jobTimeout + $grace
            : 0;

        return max(1, min($max, max($requested, $needed)));
    }

    private function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $limit);
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
