<?php

namespace App\Services\Sites\Dns;

interface DnsProvider
{
    /**
     * @return array{id: string|int|null, type: string, name: string, value: string}
     */
    public function upsertRecord(string $zone, string $type, string $name, string $value): array;

    public function deleteRecord(string $zone, string $recordId): void;

    /**
     * Whether this provider's account actually hosts the given apex zone — used
     * to auto-detect which connected credential owns a domain. Best-effort: a
     * network/auth failure resolves to false (we just can't confirm it here).
     */
    public function controlsZone(string $zone): bool;
}
