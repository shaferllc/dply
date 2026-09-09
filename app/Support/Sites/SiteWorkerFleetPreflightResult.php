<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Services\WorkerPools\SiteWorkerFleetPreflight;

/**
 * Outcome of {@see SiteWorkerFleetPreflight}.
 */
final class SiteWorkerFleetPreflightResult
{
    /**
     * @param  list<array{id: string, name: string, role: string}>  $backends
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly array $backends = [],
        public readonly ?string $networkName = null,
        public readonly bool $allowsRemoteRegion = false,
    ) {}
}
