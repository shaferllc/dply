<?php

namespace App\Services\Sites\Dns;

use App\Modules\Providers\Services\Route53Service;

class Route53DnsProvider implements DnsProvider
{
    public function __construct(
        private readonly Route53Service $service,
    ) {}

    /** @return array<string, mixed> */
    public function upsertRecord(string $zone, string $type, string $name, string $value): array
    {
        return $this->service->upsertRecord($zone, $type, $name, $value);
    }

    public function deleteRecord(string $zone, string $recordId): void
    {
        unset($zone);

        $this->service->deleteRecord($recordId);
    }

    public function controlsZone(string $zone): bool
    {
        try {
            return $this->service->hostedZoneExists($zone);
        } catch (\Throwable) {
            return false;
        }
    }
}
