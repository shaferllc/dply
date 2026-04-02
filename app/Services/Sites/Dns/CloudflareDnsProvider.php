<?php

namespace App\Services\Sites\Dns;

use App\Services\Cloudflare\CloudflareDnsService;

class CloudflareDnsProvider implements DnsProvider
{
    public function __construct(
        private readonly CloudflareDnsService $service,
    ) {}

    public function upsertRecord(string $zone, string $type, string $name, string $value): array
    {
        if (strtoupper($type) !== 'A') {
            throw new \InvalidArgumentException('Cloudflare DNS automation supports A records for preview hostnames.');
        }

        $result = $this->service->upsertARecord($zone, $name, $value);

        return [
            'id' => $result['id'] ?? null,
            'type' => 'A',
            'name' => (string) ($result['name'] ?? $name),
            'value' => $value,
        ];
    }

    public function deleteRecord(string $zone, string $recordId): void
    {
        $this->service->deleteDnsRecord($zone, $recordId);
    }
}
