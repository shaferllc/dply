<?php

namespace App\Services\Sites\Dns;

use App\Services\DigitalOceanService;

class DigitalOceanDnsProvider implements DnsProvider
{
    public function __construct(
        private readonly DigitalOceanService $service,
    ) {}

    public function upsertRecord(string $zone, string $type, string $name, string $value): array
    {
        $record = $this->service->findDomainRecord($zone, $type, $name, $value)
            ?? $this->service->createDomainRecord($zone, $type, $name, $value);

        return [
            'id' => $record['id'] ?? null,
            'type' => $type,
            'name' => $name,
            'value' => $value,
        ];
    }

    public function deleteRecord(string $zone, string $recordId): void
    {
        if ($recordId === '') {
            return;
        }

        $this->service->deleteDomainRecord($zone, (int) $recordId);
    }
}
