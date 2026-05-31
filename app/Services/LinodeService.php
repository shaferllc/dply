<?php

namespace App\Services;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LinodeService
{
    protected string $baseUrl = 'https://api.linode.com/v4';

    protected string $token;

    public function __construct(ProviderCredential $credential)
    {
        $token = $credential->getApiToken();
        if (empty($token)) {
            throw new \InvalidArgumentException('Linode API token is required.');
        }
        $this->token = $token;
    }

    /**
     * Create a new Linode instance and return its ID.
     *
     * @param  array<string>  $authorizedKeys  SSH public key strings
     */
    public function createInstance(
        string $label,
        string $region,
        string $type,
        string $image,
        array $authorizedKeys = []
    ): int {
        $body = [
            'label' => $label,
            'region' => $region,
            'type' => $type,
            'image' => $image,
            'root_pass' => Str::password(32, true, true, true),
        ];
        if ($authorizedKeys !== []) {
            $body['authorized_keys'] = $authorizedKeys;
        }

        $response = $this->request('post', '/linode/instances', $body);
        $this->assertSuccess($response, 'create instance');

        $data = $response->json();
        $instance = $data['data'] ?? $data;
        $id = $instance['id'] ?? $data['id'] ?? null;
        if ($id === null) {
            throw new \RuntimeException('Linode API did not return instance id.');
        }

        return (int) $id;
    }

    /**
     * Get instance by ID. Returns decoded JSON.
     */
    public function getInstance(int $id): array
    {
        $response = $this->request('get', "/linode/instances/{$id}");
        $this->assertSuccess($response, 'get instance');

        $data = $response->json();
        $instance = $data['data'] ?? $data;
        if (empty($instance)) {
            throw new \RuntimeException('Linode API did not return instance.');
        }

        return $instance;
    }

    /**
     * Get public IPv4 from instance. Linode may return ipv4 as array of strings or nested.
     */
    public static function getPublicIp(array $instance): ?string
    {
        $ipv4 = $instance['ipv4'] ?? [];
        if (! is_array($ipv4) || empty($ipv4)) {
            return null;
        }
        $first = $ipv4[0] ?? null;
        if (is_string($first)) {
            return $first;
        }
        if (is_array($first) && isset($first['address'])) {
            return $first['address'];
        }

        return null;
    }

    /**
     * Destroy (delete) an instance by ID.
     */
    public function destroyInstance(int $id): void
    {
        $response = $this->request('delete', "/linode/instances/{$id}");
        $this->assertSuccess($response, 'delete instance');
    }

    /**
     * List regions (for region dropdown).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRegions(): array
    {
        $response = $this->request('get', '/regions');
        $this->assertSuccess($response, 'list regions');
        $data = $response->json();

        return $data['data'] ?? [];
    }

    /**
     * List Linode types (sizes).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTypes(): array
    {
        $response = $this->request('get', '/linode/types');
        $this->assertSuccess($response, 'list types');
        $data = $response->json();

        return $data['data'] ?? [];
    }

    /**
     * Validate token (lightweight profile call).
     */
    public function validateToken(): void
    {
        $response = $this->request('get', '/profile');
        $this->assertSuccess($response, 'validate token');
    }

    /**
     * Whether a DNS domain exists in this Linode account.
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

        foreach ($this->getDomains() as $domain) {
            if (! is_array($domain)) {
                continue;
            }

            $name = strtolower((string) ($domain['domain'] ?? ''));
            if ($name === $domainName) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDomains(): array
    {
        $all = [];
        $page = 1;

        do {
            $response = $this->request('get', '/domains', ['page' => $page, 'page_size' => 100]);
            $this->assertSuccess($response, 'list domains');
            $payload = $response->json();
            $batch = $payload['data'] ?? [];
            if (is_array($batch)) {
                foreach ($batch as $domain) {
                    if (is_array($domain)) {
                        $all[] = $domain;
                    }
                }
            }
            $pages = (int) ($payload['pages'] ?? 1);
            $page++;
        } while ($page <= $pages && $page <= 50);

        return $all;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDomainRecord(int $domainId, string $type, string $name, string $zoneName, ?string $target = null): ?array
    {
        $type = strtoupper(trim($type));
        $name = self::normalizeRecordName($name, $zoneName);

        foreach ($this->getDomainRecords($domainId) as $record) {
            if (! is_array($record)) {
                continue;
            }

            if (strtoupper((string) ($record['type'] ?? '')) !== $type) {
                continue;
            }

            if (self::normalizeRecordName((string) ($record['name'] ?? ''), $zoneName) !== $name) {
                continue;
            }

            if ($target !== null && (string) ($record['target'] ?? '') !== $target) {
                continue;
            }

            return $record;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDomainRecords(int $domainId): array
    {
        $all = [];
        $page = 1;

        do {
            $response = $this->request('get', "/domains/{$domainId}/records", [
                'page' => $page,
                'page_size' => 100,
            ]);
            $this->assertSuccess($response, 'list domain records');
            $payload = $response->json();
            $batch = $payload['data'] ?? [];
            if (is_array($batch)) {
                foreach ($batch as $record) {
                    if (is_array($record)) {
                        $all[] = $record;
                    }
                }
            }
            $pages = (int) ($payload['pages'] ?? 1);
            $page++;
        } while ($page <= $pages && $page <= 50);

        return $all;
    }

    /**
     * Create or update a domain record and return the Linode record payload.
     *
     * @return array<string, mixed>
     */
    public function upsertDomainRecord(
        string $domainName,
        string $type,
        string $recordName,
        string $target,
        int $ttl = 60
    ): array {
        $domain = $this->findDomain($domainName);
        if ($domain === null) {
            throw new \RuntimeException("Linode domain {$domainName} was not found.");
        }

        $domainId = (int) ($domain['id'] ?? 0);
        if ($domainId <= 0) {
            throw new \RuntimeException('Linode domain payload did not include an id.');
        }

        $type = strtoupper(trim($type));
        $name = self::normalizeRecordName($recordName, $domainName);
        $existing = $this->findDomainRecord($domainId, $type, $name, $domainName);

        if ($existing !== null) {
            $recordId = (int) ($existing['id'] ?? 0);
            if ($recordId <= 0) {
                throw new \RuntimeException('Linode record payload did not include an id.');
            }

            $response = $this->request('put', "/domains/{$domainId}/records/{$recordId}", [
                'type' => $type,
                'name' => $name,
                'target' => $target,
                'ttl_sec' => $ttl,
            ]);
            $this->assertSuccess($response, 'update domain record');

            $record = $response->json('data') ?? $response->json();
            if (! is_array($record) || $record === []) {
                throw new \RuntimeException('Linode API did not return an updated domain record.');
            }

            return $record;
        }

        $response = $this->request('post', "/domains/{$domainId}/records", [
            'type' => $type,
            'name' => $name,
            'target' => $target,
            'ttl_sec' => $ttl,
        ]);
        $this->assertSuccess($response, 'create domain record');

        $record = $response->json('data') ?? $response->json();
        if (! is_array($record) || $record === []) {
            throw new \RuntimeException('Linode API did not return a domain record.');
        }

        return $record;
    }

    public function deleteDomainRecord(string $domainName, int $recordId): void
    {
        if ($recordId <= 0) {
            return;
        }

        $domain = $this->findDomain($domainName);
        if ($domain === null) {
            return;
        }

        $domainId = (int) ($domain['id'] ?? 0);
        if ($domainId <= 0) {
            return;
        }

        $response = $this->request('delete', "/domains/{$domainId}/records/{$recordId}");
        if ($response->status() === 404) {
            return;
        }

        $this->assertSuccess($response, 'delete domain record');
    }

    /**
     * Map dply relative record names to Linode record names (empty string for apex).
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
        if (strtolower($method) === 'put') {
            return $request->put($url, $body);
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

        $errors = $response->json('errors');
        $message = is_array($errors) && isset($errors[0]['reason']) ? $errors[0]['reason'] : $response->body();

        throw new \RuntimeException("Linode API failed to {$action}: {$message}");
    }
}
