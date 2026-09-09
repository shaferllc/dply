<?php

declare(strict_types=1);

namespace App\Modules\Queue\Support;

use App\Modules\Queue\Models\ManagedQueueFleet;

/**
 * Everything a runtime needs to start one worker, and nothing about how.
 *
 * The endpoint and credentials in here are the *same* ones an external app
 * would use. A dply-owned worker gets no privileged path into the store —
 * if it did, the managed product and the self-hosted one would drift apart
 * silently, and only the self-hosted one would be exercised by customers.
 */
final readonly class WorkerSpec
{
    /**
     * @param  string  $image  the customer's app image
     * @param  array<string, string>  $env  injected on top of the app's own
     * @param  int  $graceSeconds  time to finish the current job on stop
     */
    public function __construct(
        public string $fleetId,
        public string $queue,
        public string $image,
        public int $memoryMib,
        public int $graceSeconds,
        public array $env = [],
    ) {}

    public static function forFleet(ManagedQueueFleet $fleet, string $image, array $env = []): self
    {
        return new self(
            fleetId: $fleet->id,
            queue: $fleet->queue,
            image: $image,
            memoryMib: $fleet->memory_mib,
            // The class *is* the grace period: flex runs on capacity that can
            // be reclaimed at any moment, pro promises long jobs finish.
            graceSeconds: $fleet->graceSeconds(),
            env: $env,
        );
    }
}
