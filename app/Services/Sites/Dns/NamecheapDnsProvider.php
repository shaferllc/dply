<?php

declare(strict_types=1);

namespace App\Services\Sites\Dns;

use App\Modules\Providers\Namecheap\NamecheapDnsService;

class NamecheapDnsProvider implements DnsProvider
{
    public function __construct(
        private readonly NamecheapDnsService $service,
    ) {}

    /** @return array<string, mixed> */
    public function upsertRecord(string $zone, string $type, string $name, string $value): array
    {
        $type = strtoupper($type);

        $result = match ($type) {
            'A' => $this->service->upsertARecord($zone, $name, $value),
            'TXT' => $this->service->upsertTxtRecord($zone, $name, $value),
            default => throw new \InvalidArgumentException('Namecheap DNS automation supports A and TXT records for preview hostnames.'),
        };

        return [
            'id' => $result['id'] ?? $name.'/'.$type,
            'type' => $type,
            'name' => (string) ($result['name'] ?? $name),
            'value' => $value,
        ];
    }

    public function deleteRecord(string $zone, string $recordId): void
    {
        $this->service->deleteDnsRecord($zone, $recordId);
    }

    public function controlsZone(string $zone): bool
    {
        try {
            return $this->service->zoneExists($zone);
        } catch (\Throwable) {
            return false;
        }
    }
}
