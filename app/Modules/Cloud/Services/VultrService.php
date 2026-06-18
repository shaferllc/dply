<?php

namespace App\Modules\Cloud\Services;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class VultrService
{
    protected string $baseUrl = 'https://api.vultr.com/v2';

    protected string $token;

    /**
     * @param  ProviderCredential|non-empty-string  $credentialOrToken  Saved credential or a raw API token string.
     */
    public function __construct(ProviderCredential|string $credentialOrToken)
    {
        $token = $credentialOrToken instanceof ProviderCredential
            ? $credentialOrToken->getApiToken()
            : $credentialOrToken;
        $token = is_string($token) ? trim($token) : '';
        if ($token === '') {
            throw new \InvalidArgumentException('Vultr API token is required.');
        }
        $this->token = $token;
    }

    /**
     * Build a service bound to a raw API token (e.g. the app-level base key).
     */
    public static function fromToken(string $token): self
    {
        return new self($token);
    }

    /**
     * Build a service bound to the app-level base key (services.vultr.token /
     * VULTR_TOKEN) for global operations with no connected customer credential.
     * Returns null when no base key is configured.
     */
    public static function fromConfig(): ?self
    {
        $token = trim((string) config('services.vultr.token', ''));

        return $token === '' ? null : new self($token);
    }

    /**
     * Create an SSH key and return its ID.
     */
    public function createSshKey(string $name, string $publicKey): string
    {
        $response = $this->request('post', '/ssh-keys', [
            'name' => $name,
            'ssh_key' => trim($publicKey),
        ]);
        $this->assertSuccess($response, 'create SSH key');

        $data = $response->json();
        $key = $data['ssh_key'] ?? $data;
        $id = $key['id'] ?? $data['id'] ?? null;
        if (empty($id)) {
            throw new \RuntimeException('Vultr API did not return SSH key id.');
        }

        return (string) $id;
    }

    /**
     * Create a new instance and return its ID.
     *
     * @param  array<string, mixed> $sshKeyIds  SSH key IDs from createSshKey()
     */
    public function createInstance(
        string $region,
        string $plan,
        int $osId,
        string $label,
        array $sshKeyIds = []
    ): string {
        $body = [
            'region' => $region,
            'plan' => $plan,
            'os_id' => $osId,
            'label' => $label,
        ];
        if ($sshKeyIds !== []) {
            $body['sshkey_id'] = $sshKeyIds;
        }

        $response = $this->request('post', '/instances', $body);
        $this->assertSuccess($response, 'create instance');

        $data = $response->json();
        $instance = $data['instance'] ?? $data;
        $id = $instance['id'] ?? $data['id'] ?? null;
        if (empty($id)) {
            throw new \RuntimeException('Vultr API did not return instance id.');
        }

        return (string) $id;
    }

    /**
     * Get instance by ID. Returns decoded JSON.
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function getInstance(string $id): array
    {
        $response = $this->request('get', '/instances/'.$id);
        $this->assertSuccess($response, 'get instance');

        $data = $response->json();
        $instance = $data['instance'] ?? $data;
        if (empty($instance)) {
            throw new \RuntimeException('Vultr API did not return instance.');
        }

        return $instance;
    }

    /**
     * Get public IPv4 from instance.
     * @param  array<string, mixed> $instance
     */
    public static function getPublicIp(array $instance): ?string
    {
        $ip = $instance['main_ip'] ?? $instance['v4_main_ip'] ?? $instance['ip_address'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    /**
     * Private / internal IPv4 from an instance array. Vultr exposes the legacy
     * private-network address as `internal_ip` (empty string when the instance
     * isn't on a private network); VPC attachments live under `vpcs[].subnet`.
     * Returns null when the instance has no private networking — same null-safe
     * contract as {@see DigitalOceanService::getDropletPrivateIp()}.
     * @param  array<string, mixed> $instance
     */
    public static function getPrivateIp(array $instance): ?string
    {
        $internal = $instance['internal_ip'] ?? null;
        if (is_string($internal) && trim($internal) !== '') {
            return trim($internal);
        }

        foreach ($instance['vpcs'] ?? [] as $vpc) {
            $subnet = is_array($vpc) ? ($vpc['subnet'] ?? null) : null;
            if (is_string($subnet) && trim($subnet) !== '') {
                return trim($subnet);
            }
        }

        return null;
    }

    /**
     * The id of the VPC the instance is attached to (Vultr instances are NOT on a
     * private network by default — this is null unless one was attached). The id
     * is the identity used to record the instance's private network.
     * @param  array<string, mixed> $instance
     */
    public static function getInstanceVpcId(array $instance): ?string
    {
        foreach ($instance['vpcs'] ?? [] as $vpc) {
            $id = is_array($vpc) ? ($vpc['id'] ?? null) : null;
            if (is_string($id) && trim($id) !== '') {
                return trim($id);
            }
        }

        return null;
    }

    /**
     * Best-effort CIDR for the attached VPC (subnet + mask). Null when the mask
     * isn't present — recording by VPC id alone still enables peering.
     * @param  array<string, mixed> $instance
     */
    public static function getInstanceVpcRange(array $instance): ?string
    {
        foreach ($instance['vpcs'] ?? [] as $vpc) {
            if (! is_array($vpc)) {
                continue;
            }
            $subnet = trim((string) ($vpc['subnet'] ?? ''));
            $mask = (int) ($vpc['subnet_size'] ?? $vpc['mask'] ?? 0);
            if ($subnet !== '' && $mask > 0 && $mask <= 32) {
                return $subnet.'/'.$mask;
            }
        }

        return null;
    }

    /**
     * Destroy (delete) an instance by ID.
     */
    public function destroyInstance(string $id): void
    {
        $response = $this->request('delete', '/instances/'.$id);
        $this->assertSuccess($response, 'delete instance');
    }

    /**
     * Create a snapshot of an instance. Returns the new snapshot's ID. The capture
     * runs asynchronously on Vultr's side — poll {@see waitForSnapshot()} for
     * completion.
     */
    public function createSnapshot(string $instanceId, string $description): string
    {
        $response = $this->request('post', '/snapshots', [
            'instance_id' => $instanceId,
            'description' => $description,
        ]);
        $this->assertSuccess($response, 'create snapshot');

        $data = $response->json();
        $snapshot = $data['snapshot'] ?? $data;
        $id = $snapshot['id'] ?? $data['id'] ?? null;
        if (empty($id)) {
            throw new \RuntimeException('Vultr API did not return snapshot id.');
        }

        return (string) $id;
    }

    /**
     * Get a snapshot by ID. Returns the decoded `snapshot` object — notable fields:
     * `status` (pending|complete), `size` (bytes), `date_created`.
     *
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function getSnapshot(string $id): array
    {
        $response = $this->request('get', '/snapshots/'.$id);
        $this->assertSuccess($response, 'get snapshot');

        $data = $response->json();
        $snapshot = $data['snapshot'] ?? $data;
        if (! is_array($snapshot) || $snapshot === []) {
            throw new \RuntimeException('Vultr API did not return snapshot.');
        }

        return $snapshot;
    }

    /**
     * Poll a snapshot until it reports `status == complete`. Vultr has no
     * action-object model (unlike DO/Hetzner), so completion is read off the
     * snapshot's own status field. Returns the final snapshot array.
     *
     * MUST run inside a queue job — it blocks while polling.
     *
     * @param  callable(array<string, mixed>):void|null  $onTick  receives each poll's snapshot
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function waitForSnapshot(string $id, ?callable $onTick = null, int $maxAttempts = 360, int $sleepSeconds = 10): array
    {
        $snapshot = [];
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $snapshot = $this->getSnapshot($id);
            if ($onTick !== null) {
                $onTick($snapshot);
            }
            if ((string) ($snapshot['status'] ?? '') === 'complete') {
                return $snapshot;
            }
            sleep($sleepSeconds);
        }

        throw new \RuntimeException('Timed out waiting for Vultr snapshot '.$id.' to complete.');
    }

    /**
     * Delete a snapshot by ID. A 404 (already gone) is treated as success.
     */
    public function deleteSnapshot(string $id): void
    {
        if ($id === '') {
            return;
        }

        $response = $this->request('delete', '/snapshots/'.$id);
        if ($response->status() === 404) {
            return;
        }

        $this->assertSuccess($response, 'delete snapshot');
    }

    /**
     * List regions. Normalizes to list of [id, city, ...] (API may return object keyed by id).
     *
     * @return array<int, array<string, mixed>>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<mixed>>
     */
    public function getRegions(): array
    {
        $response = $this->request('get', '/regions');
        $this->assertSuccess($response, 'list regions');
        $data = $response->json();
        $raw = $data['regions'] ?? [];
        if (! is_array($raw)) {
            return [];
        }
        if (array_is_list($raw)) {
            return $raw;
        }
        $list = [];
        foreach ($raw as $id => $item) {
            $list[] = array_merge(is_array($item) ? $item : [], ['id' => $id]);
        }

        return $list;
    }

    /**
     * List plans. Normalizes to list (API may return object keyed by id).
     *
     * @return list<array<mixed>>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<mixed>>
     */
    public function getPlans(): array
    {
        $response = $this->request('get', '/plans');
        $this->assertSuccess($response, 'list plans');
        $data = $response->json();
        $raw = $data['plans'] ?? [];
        if (! is_array($raw)) {
            return [];
        }
        if (array_is_list($raw)) {
            return $raw;
        }
        $list = [];
        foreach ($raw as $id => $item) {
            $list[] = array_merge(is_array($item) ? $item : [], ['id' => $id]);
        }

        return $list;
    }

    /**
     * List OS images (optional; can use default_os_id from config).
     *
     * @return list<array<mixed>>
     */
    /** @return array<string, mixed> */
    public function getOsList(): array
    {
        $response = $this->request('get', '/os');
        $this->assertSuccess($response, 'list OS');
        $data = $response->json();

        return $data['os'] ?? [];
    }

    /**
     * Validate token (GET account).
     */
    public function validateToken(): void
    {
        $response = $this->request('get', '/account');
        $this->assertSuccess($response, 'validate token');
    }

    /**
     * Whether a DNS domain exists in this Vultr account.
     */
    public function domainExists(string $domainName): bool
    {
        return $this->findDomain($domainName) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDomain(string $domainName): ?array
    {
        $domainName = strtolower(trim($domainName));
        if ($domainName === '') {
            return null;
        }

        $response = $this->request('get', '/domains/'.rawurlencode($domainName));
        if ($response->status() === 404) {
            return null;
        }

        $this->assertSuccess($response, 'get domain');

        $domain = $response->json('domain');

        return is_array($domain) ? $domain : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<mixed, mixed>>
     */
    public function getDomainRecords(string $domainName): array
    {
        $all = [];
        $cursor = null;

        do {
            $query = $cursor !== null ? ['cursor' => $cursor] : [];
            $response = $this->request('get', '/domains/'.rawurlencode($domainName).'/records', $query);
            $this->assertSuccess($response, 'list domain records');
            $payload = $response->json();
            $batch = $payload['records'] ?? [];
            if (is_array($batch)) {
                foreach ($batch as $record) {
                    if (is_array($record)) {
                        $all[] = $record;
                    }
                }
            }

            $next = $payload['meta']['links']['next'] ?? null;
            $cursor = null;
            if (is_string($next) && preg_match('/cursor=([^&]+)/', $next, $matches) === 1) {
                $cursor = urldecode($matches[1]);
            }
        } while ($cursor !== null);

        return $all;
    }

    /**
     * @return list<array<mixed, mixed>>
     */
    public function findDomainRecord(string $domainName, string $type, string $name, ?string $data = null): ?array
    {
        $type = strtoupper(trim($type));
        $name = self::normalizeRecordName($name, $domainName);

        foreach ($this->getDomainRecords($domainName) as $record) {
            if (! is_array($record)) {
                continue;
            }

            if (strtoupper((string) ($record['type'] ?? '')) !== $type) {
                continue;
            }

            if (self::normalizeRecordName((string) ($record['name'] ?? ''), $domainName) !== $name) {
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
     * Create or update a domain record and return the Vultr record payload.
     *
     * @return array<string, mixed>
     */
    public function upsertDomainRecord(
        string $domainName,
        string $type,
        string $recordName,
        string $data,
        int $ttl = 60
    ): array {
        $domainName = strtolower(trim($domainName));
        $type = strtoupper(trim($type));
        $name = self::normalizeRecordName($recordName, $domainName);
        $existing = $this->findDomainRecord($domainName, $type, $name);

        if ($existing !== null) {
            $recordId = (string) ($existing['id'] ?? '');
            if ($recordId === '') {
                throw new \RuntimeException('Vultr record payload did not include an id.');
            }

            $response = $this->request('patch', '/domains/'.rawurlencode($domainName).'/records/'.rawurlencode($recordId), [
                'name' => $name,
                'data' => $data,
                'ttl' => max(60, $ttl),
            ]);
            $this->assertSuccess($response, 'update domain record');

            $record = $response->json('record');
            if (! is_array($record) || $record === []) {
                return array_merge($existing, ['data' => $data, 'ttl' => max(60, $ttl)]);
            }

            return $record;
        }

        $response = $this->request('post', '/domains/'.rawurlencode($domainName).'/records', [
            'type' => $type,
            'name' => $name,
            'data' => $data,
            'ttl' => max(60, $ttl),
        ]);
        $this->assertSuccess($response, 'create domain record');

        $record = $response->json('record');
        if (! is_array($record) || $record === []) {
            throw new \RuntimeException('Vultr API did not return a domain record.');
        }

        return $record;
    }

    public function deleteDomainRecord(string $domainName, string $recordId): void
    {
        if ($recordId === '') {
            return;
        }

        $response = $this->request(
            'delete',
            '/domains/'.rawurlencode(strtolower(trim($domainName))).'/records/'.rawurlencode($recordId)
        );
        if ($response->status() === 404) {
            return;
        }

        $this->assertSuccess($response, 'delete domain record');
    }

    /**
     * Map dply relative record names to Vultr record names (empty string for apex).
     */
    public static function normalizeRecordName(string $recordName, string $zoneName): string
    {
        $recordName = strtolower(trim($recordName));
        $zoneName = strtolower(trim($zoneName));

        if ($recordName === '' || $recordName === '@' || ($zoneName !== '' && $recordName === $zoneName)) {
            return '';
        }

        if ($zoneName !== '' && str_ends_with($recordName, '.'.$zoneName)) {
            $recordName = substr($recordName, 0, -1 * (strlen($zoneName) + 1));
        }

        return $recordName;
    }

    /**
     * @param  array<string, mixed> $body
     */
    protected function request(string $method, string $path, array $body = []): Response
    {
        $url = $this->baseUrl.$path;
        $request = Http::withToken($this->token)
            ->acceptJson()
            ->contentType('application/json');

        if (strtolower($method) === 'get' && $body !== []) {
            return $request->get($url, $body);
        }
        if (strtolower($method) === 'get') {
            return $request->get($url);
        }
        if (strtolower($method) === 'post') {
            return $request->post($url, $body);
        }
        if (strtolower($method) === 'patch') {
            return $request->patch($url, $body);
        }
        if (strtolower($method) === 'delete') {
            return $request->delete($url);
        }

        throw new \InvalidArgumentException("Unsupported method: {$method}");
    }

    protected function assertSuccess(Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $error = $response->json('error');
        $message = is_string($error) ? $error : ($response->body() ?: $response->reason());

        throw new \RuntimeException("Vultr API failed to {$action}: {$message}");
    }
}
