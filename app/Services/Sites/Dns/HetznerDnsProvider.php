<?php

namespace App\Services\Sites\Dns;

use App\Services\HetznerService;

class HetznerDnsProvider implements DnsProvider
{
    public function __construct(
        private readonly HetznerService $service,
    ) {}

    public function upsertRecord(string $zone, string $type, string $name, string $value): array
    {
        $this->service->upsertZoneRecord($zone, $type, $name, $value);

        $rrName = HetznerService::normalizeRrsetName($name, $zone);
        $typeUpper = strtoupper($type);

        return [
            'id' => $rrName.'/'.$typeUpper,
            'type' => $typeUpper,
            'name' => $rrName,
            'value' => $value,
        ];
    }

    public function deleteRecord(string $zone, string $recordId): void
    {
        if ($recordId === '' || ! str_contains($recordId, '/')) {
            return;
        }

        [$name, $type] = explode('/', $recordId, 2);
        $this->service->deleteZoneRrset($zone, $name, $type);
    }
}
