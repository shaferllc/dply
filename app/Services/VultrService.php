<?php

namespace App\Services;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class VultrService
{
    protected string $baseUrl = 'https://api.vultr.com/v2';

    protected string $token;

    public function __construct(ProviderCredential $credential)
    {
        $token = $credential->getApiToken();
        if (empty($token)) {
            throw new \InvalidArgumentException('Vultr API token is required.');
        }
        $this->token = $token;
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
     * @param  array<string>  $sshKeyIds  SSH key IDs from createSshKey()
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
     */
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
     */
    public static function getPublicIp(array $instance): ?string
    {
        $ip = $instance['main_ip'] ?? $instance['v4_main_ip'] ?? $instance['ip_address'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : null;
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
     * List regions. Normalizes to list of [id, city, ...] (API may return object keyed by id).
     *
     * @return array<int, array<string, mixed>>
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
     * @return array<int, array<string, mixed>>
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
     * @return array<int, array<string, mixed>>
     */
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
     * @return array<string, mixed>|null
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
