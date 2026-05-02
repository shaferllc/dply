<?php

namespace App\Services\Sites\Dns;

interface DnsProvider
{
    /**
     * @return array{id: string|int|null, type: string, name: string, value: string}
     */
    public function upsertRecord(string $zone, string $type, string $name, string $value): array;

    public function deleteRecord(string $zone, string $recordId): void;
}
