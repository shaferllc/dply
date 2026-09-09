<?php

namespace App\Services\Sites\Dns;

use App\Modules\Providers\Services\DigitalOceanService;

class DigitalOceanDnsProvider implements DnsProvider
{
    public function __construct(
        private readonly DigitalOceanService $service,
    ) {}

    /** @return array<string, mixed> */
    public function upsertRecord(string $zone, string $type, string $name, string $value): array
    {
        // Replace, don't append. This used to look the record up BY VALUE and
        // create one when that missed — so re-pointing a hostname at a new
        // server never matched the existing record and left both addresses
        // live on the same name. DNS then round-robined between the old and
        // new box. Every other provider here already delegates to a real
        // upsert; this one now does too.
        $record = $this->service->upsertDomainRecord($zone, $type, $name, $value);

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

    public function controlsZone(string $zone): bool
    {
        try {
            return $this->service->domainExists($zone);
        } catch (\Throwable) {
            return false;
        }
    }
}
