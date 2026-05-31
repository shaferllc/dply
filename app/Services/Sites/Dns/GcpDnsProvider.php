<?php

namespace App\Services\Sites\Dns;

use App\Services\GcpDnsService;

class GcpDnsProvider implements DnsProvider
{
    public function __construct(
        private readonly GcpDnsService $service,
    ) {}

    public function upsertRecord(string $zone, string $type, string $name, string $value): array
    {
        $record = $this->service->upsertRecord($zone, $type, $name, $value);

        return [
            'id' => (string) ($record['id'] ?? ''),
            'type' => strtoupper($type),
            'name' => GcpDnsService::normalizeRecordName($name, $zone),
            'value' => $value,
        ];
    }

    public function deleteRecord(string $zone, string $recordId): void
    {
        if ($recordId === '') {
            return;
        }

        $this->service->deleteRecord($zone, $recordId);
    }
}
