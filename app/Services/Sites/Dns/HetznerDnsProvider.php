<?php

namespace App\Services\Sites\Dns;

use App\Modules\Cloud\Services\HetznerService;

class HetznerDnsProvider implements DnsProvider
{
    public function __construct(
        private readonly HetznerService $service,
    ) {}

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function upsertRecord(string $zone, string $type, string $name, string $value): array
    {
        // Auto-create the zone if it isn't registered on this Hetzner
        // project yet. Dply uses this for its testing-zone pool
        // (services.dply.testing_domains.hetzner) so operators don't have
        // to pre-create on-dply.cc / on-dply.cloud / etc. by hand in the
        // Hetzner DNS console.
        if (! $this->service->zoneExists($zone)) {
            $this->service->createZone($zone);
        }

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
