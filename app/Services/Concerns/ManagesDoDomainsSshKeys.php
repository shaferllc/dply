<?php

declare(strict_types=1);

namespace App\Services\Concerns;



/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDoDomainsSshKeys
{


    /**
     * List account SSH keys.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSshKeys(): array
    {
        $response = $this->request('get', '/account/keys');
        $this->assertSuccess($response, 'list SSH keys');
        $data = $response->json();
        $keys = $data['ssh_keys'] ?? $data['data'] ?? [];

        return is_array($keys) ? $keys : [];
    }

    /**
     * Add an SSH public key to the account. Returns key array with id.
     *
     * @return array<string, mixed>
     */
    public function addSshKey(string $name, string $publicKey): array
    {
        $response = $this->request('post', '/account/keys', [
            'name' => $name,
            'public_key' => trim($publicKey),
        ]);
        $this->assertSuccess($response, 'create SSH key');
        $data = $response->json();
        $key = $data['ssh_key'] ?? $data;
        if (empty($key) || ! is_array($key)) {
            throw new \RuntimeException('DigitalOcean API did not return SSH key.');
        }

        return $key;
    }

    /**
     * Delete an account SSH key by its DO numeric id or fingerprint.
     */
    public function deleteSshKey(int|string $idOrFingerprint): void
    {
        $value = is_string($idOrFingerprint) ? trim($idOrFingerprint) : (string) $idOrFingerprint;
        if ($value === '') {
            throw new \InvalidArgumentException('SSH key id or fingerprint is required.');
        }

        $response = $this->request('delete', '/account/keys/'.rawurlencode($value));
        $this->assertSuccess($response, 'delete SSH key');
    }

    /**
     * Whether the domain exists in this DigitalOcean account (Networking → Domains).
     */
    public function domainExistsInAccount(string $domain): bool
    {
        return $this->fetchDomain($domain) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchDomain(string $domain): ?array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return null;
        }

        $encoded = rawurlencode($domain);
        $response = $this->request('get', '/domains/'.$encoded);
        if ($response->status() === 404) {
            return null;
        }
        $this->assertSuccess($response, 'get domain');
        $data = $response->json();
        $payload = $data['domain'] ?? null;

        return is_array($payload) ? $payload : null;
    }

    /**
     * List every DNS record in a zone, following DO's pagination.
     *
     * DO paginates record lists (20 per page by default). Returning only the
     * first page silently truncates large zones — and callers that purge
     * conflicting records before a CNAME write MUST see every record, or DO
     * rejects the create with "CNAME records cannot share a name with other
     * records". So we request the max page size and walk `links.pages.next`
     * until the zone is exhausted.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDomainRecords(string $domain, array $query = []): array
    {
        $all = [];
        $page = 1;

        // Hard cap at 50 pages (× 200 = 10k records) so a malformed
        // pagination response can never spin this loop forever.
        do {
            $response = $this->request('get', '/domains/'.$domain.'/records', array_merge($query, [
                'per_page' => 200,
                'page' => $page,
            ]));
            $this->assertSuccess($response, 'list domain records');
            $data = $response->json();
            $records = $data['domain_records'] ?? $data['data'] ?? [];

            if (is_array($records)) {
                foreach ($records as $record) {
                    $all[] = $record;
                }
            }

            $hasNext = is_array($records) && $records !== []
                && is_string(data_get($data, 'links.pages.next'));
            $page++;
        } while ($hasNext && $page <= 50);

        return $all;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDomainRecord(string $domain, string $type, string $name, ?string $data = null): ?array
    {
        $type = strtoupper($type);
        $records = $this->getDomainRecords($domain, ['type' => $type, 'name' => $name]);

        if ($records === []) {
            $records = $this->getDomainRecords($domain);
        }

        foreach ($records as $record) {
            if (strtoupper((string) ($record['type'] ?? '')) !== $type) {
                continue;
            }

            if ((string) ($record['name'] ?? '') !== $name) {
                continue;
            }

            if ($data !== null && (string) ($record['data'] ?? '') !== $data) {
                continue;
            }

            return $record;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function createDomainRecord(
        string $domain,
        string $type,
        string $name,
        string $data,
        int $ttl = 60
    ): array {
        $response = $this->request('post', '/domains/'.$domain.'/records', [
            'type' => strtoupper($type),
            'name' => $name,
            'data' => $data,
            'ttl' => $ttl,
        ]);
        $this->assertSuccess($response, 'create domain record');
        $payload = $response->json();
        $record = $payload['domain_record'] ?? $payload;

        if (! is_array($record) || $record === []) {
            throw new \RuntimeException('DigitalOcean API did not return a domain record.');
        }

        return $record;
    }

    /**
     * Every record matching $type + $name, regardless of value.
     *
     * {@see findDomainRecord()} takes an optional value and stops at the first
     * hit, which answers "does this exact record exist". Replacing a record
     * needs the other question — "what is currently answering for this name" —
     * including the duplicates a previous append-style upsert left behind.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findDomainRecords(string $domain, string $type, string $name): array
    {
        $type = strtoupper($type);

        $records = $this->getDomainRecords($domain, ['type' => $type, 'name' => $name]);
        if ($records === []) {
            $records = $this->getDomainRecords($domain);
        }

        return array_values(array_filter($records, static function (mixed $record) use ($type, $name): bool {
            return is_array($record)
                && strtoupper((string) ($record['type'] ?? '')) === $type
                && (string) ($record['name'] ?? '') === $name;
        }));
    }

    /**
     * @return array<string, mixed>
     */
    public function updateDomainRecord(
        string $domain,
        int $recordId,
        string $type,
        string $name,
        string $data,
        int $ttl = 60
    ): array {
        $response = $this->request('put', '/domains/'.$domain.'/records/'.$recordId, [
            'type' => strtoupper($type),
            'name' => $name,
            'data' => $data,
            'ttl' => $ttl,
        ]);
        $this->assertSuccess($response, 'update domain record');
        $payload = $response->json();
        $record = $payload['domain_record'] ?? $payload;

        if (! is_array($record) || $record === []) {
            throw new \RuntimeException('DigitalOcean API did not return a domain record.');
        }

        return $record;
    }

    /**
     * Point $name at $data, replacing whatever was there.
     *
     * DNS has no upsert, so this is find → update → create, the same shape
     * {@see \App\Modules\Providers\Services\VultrService::upsertDomainRecord()}
     * uses. It also deletes surplus records for the same name: a hostname is
     * being pointed at ONE server, and leaving the previous address alongside
     * the new one round-robins traffic between them — half the requests land
     * on the old box, which reads as an intermittent, cache-shaped outage.
     *
     * That is not hypothetical cleanup. The previous implementation matched on
     * VALUE before creating, so a changed IP never matched, and every re-point
     * appended another A record instead of replacing one.
     *
     * @return array<string, mixed>
     */
    public function upsertDomainRecord(
        string $domain,
        string $type,
        string $name,
        string $data,
        int $ttl = 60
    ): array {
        $existing = $this->findDomainRecords($domain, $type, $name);

        if ($existing === []) {
            return $this->createDomainRecord($domain, $type, $name, $data, $ttl);
        }

        // Prefer a record that already holds the wanted value, so the common
        // no-op re-apply neither rewrites nor renumbers anything.
        $keep = null;
        foreach ($existing as $record) {
            if ((string) ($record['data'] ?? '') === $data) {
                $keep = $record;
                break;
            }
        }
        $keep ??= $existing[0];
        $keepId = (int) ($keep['id'] ?? 0);

        foreach ($existing as $record) {
            $recordId = (int) ($record['id'] ?? 0);
            if ($recordId !== 0 && $recordId !== $keepId) {
                $this->deleteDomainRecord($domain, $recordId);
            }
        }

        if ($keepId === 0) {
            return $this->createDomainRecord($domain, $type, $name, $data, $ttl);
        }

        if ((string) ($keep['data'] ?? '') === $data) {
            return $keep;
        }

        return $this->updateDomainRecord($domain, $keepId, $type, $name, $data, $ttl);
    }

    public function deleteDomainRecord(string $domain, int $recordId): void
    {
        $response = $this->request('delete', '/domains/'.$domain.'/records/'.$recordId);
        $this->assertSuccess($response, 'delete domain record');
    }
}
