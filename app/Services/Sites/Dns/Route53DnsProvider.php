<?php

namespace App\Services\Sites\Dns;

use App\Services\Route53Service;

class Route53DnsProvider implements DnsProvider
{
    public function __construct(
        private readonly Route53Service $service,
    ) {}

    public function upsertRecord(string $zone, string $type, string $name, string $value): array
    {
        return $this->service->upsertRecord($zone, $type, $name, $value);
    }

    public function deleteRecord(string $zone, string $recordId): void
    {
        // Route53 deletions are not wired yet because record payload reconstruction is still missing.
    }
}
