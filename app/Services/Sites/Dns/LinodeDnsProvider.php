<?php

namespace App\Services\Sites\Dns;

use App\Services\LinodeService;

class LinodeDnsProvider implements DnsProvider
{
    public function __construct(
        private readonly LinodeService $service,
    ) {}

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function upsertRecord(string $zone, string $type, string $name, string $value): array
    {
        $record = $this->service->upsertDomainRecord($zone, $type, $name, $value);

        return [
            'id' => (string) ($record['id'] ?? ''),
            'type' => strtoupper($type),
            'name' => LinodeService::normalizeRecordName($name, $zone),
            'value' => $value,
        ];
    }

    public function deleteRecord(string $zone, string $recordId): void
    {
        if ($recordId === '' || ! ctype_digit($recordId)) {
            return;
        }

        $this->service->deleteDomainRecord($zone, (int) $recordId);
    }
}
