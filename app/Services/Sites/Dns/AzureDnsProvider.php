<?php

namespace App\Services\Sites\Dns;

use App\Modules\Cloud\Services\AzureDnsService;

class AzureDnsProvider implements DnsProvider
{
    public function __construct(
        private readonly AzureDnsService $service,
    ) {}

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function upsertRecord(string $zone, string $type, string $name, string $value): array
    {
        $record = $this->service->upsertRecord($zone, $type, $name, $value);

        return [
            'id' => (string) ($record['id'] ?? ''),
            'type' => strtoupper($type),
            'name' => AzureDnsService::normalizeRecordName($name, $zone),
            'value' => $value,
        ];
    }

    public function deleteRecord(string $zone, string $recordId): void
    {
        if ($recordId === '') {
            return;
        }

        $this->service->deleteRecordById($recordId);
    }
}
