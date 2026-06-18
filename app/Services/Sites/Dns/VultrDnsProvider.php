<?php

namespace App\Services\Sites\Dns;

use App\Services\VultrService;

class VultrDnsProvider implements DnsProvider
{
    public function __construct(
        private readonly VultrService $service,
    ) {}

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function upsertRecord(string $zone, string $type, string $name, string $value): array
    {
        $record = $this->service->upsertDomainRecord($zone, $type, $name, $value);

        return [
            'id' => (string) ($record['id'] ?? ''),
            'type' => strtoupper($type),
            'name' => VultrService::normalizeRecordName($name, $zone),
            'value' => $value,
        ];
    }

    public function deleteRecord(string $zone, string $recordId): void
    {
        if ($recordId === '') {
            return;
        }

        $this->service->deleteDomainRecord($zone, $recordId);
    }
}
