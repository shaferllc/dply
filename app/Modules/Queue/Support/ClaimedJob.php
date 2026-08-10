<?php

declare(strict_types=1);

namespace App\Modules\Queue\Support;

/**
 * One job handed to a consumer, plus the reservation that owns it.
 *
 * `reservationId` is the fencing token. It must be presented on ack, release,
 * fail, and heartbeat — the store matches on it, so a worker whose lease
 * expired while it was stalled cannot mutate the row that a newer consumer
 * now holds. Losing that check means silent job loss, not just a double run.
 */
final readonly class ClaimedJob
{
    public function __construct(
        public string $id,
        public string $queue,
        public string $payload,
        public int $attempts,
        public string $reservationId,
        /** Absolute moment the lease expires, as an ISO-8601 string. */
        public string $visibleAt,
        public ?string $jobUuid = null,
        public ?string $displayName = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'queue' => $this->queue,
            'payload' => $this->payload,
            'attempts' => $this->attempts,
            'reservation_id' => $this->reservationId,
            'visible_at' => $this->visibleAt,
            'job_uuid' => $this->jobUuid,
            'display_name' => $this->displayName,
        ];
    }
}
