<?php

declare(strict_types=1);

namespace App\Modules\Queue\Support;

/**
 * What the runtime hands back when a worker exists.
 *
 * `ref` is opaque on purpose — a Docker container id today, something else
 * later — and only ever travels back into the runtime that issued it.
 */
final readonly class WorkerHandle
{
    public function __construct(
        public string $ref,
        public string $runtime,
        public ?string $hostServerId = null,
    ) {}
}
