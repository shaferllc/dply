<?php

namespace App\Modules\Cloud\Services;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LinodeService
{
    protected string $baseUrl = 'https://api.linode.com/v4';

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
            throw new \InvalidArgumentException('Linode API token is required.');
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
     * Build a service bound to the app-level base key (services.linode.token /
     * LINODE_TOKEN) for global operations with no connected customer credential.
     * Returns null when no base key is configured.
     */
    public static function fromConfig(): ?self
    {
        $token = trim((string) config('services.linode.token', ''));

        return $token === '' ? null : new self($token);
    }

    /**
     * Create a new Linode instance and return its ID.
     *
     * @param  array<string, mixed> $authorizedKeys  SSH public key strings
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
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
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
     * @param  array<string, mixed> $instance
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
     * Private IPv4 from an instance array. Linode lists every address (public +
     * private) in `ipv4`; private addresses live in the 192.168.0.0/16 range
     * (Linode allocates private IPs from 192.168.128.0/17). Returns null when the
     * Linode has no private networking — same null-safe contract as the other
     * providers' private-IP readers.
     * @param  array<string, mixed> $instance
     */
    public static function getPrivateIp(array $instance): ?string
    {
        foreach ($instance['ipv4'] ?? [] as $entry) {
            $address = is_string($entry) ? $entry : (is_array($entry) ? ($entry['address'] ?? '') : '');
            $address = trim((string) $address);
            if ($address !== '' && str_starts_with($address, '192.168.')) {
                return $address;
            }
        }

        return null;
    }

    /**
     * List a Linode's disks. Used to locate the bootable disk to image.
     *
     * @return array<int, array<string, mixed>>
     */
    /** @return array<string, mixed> */
    public function getInstanceDisks(int $id): array
    {
        $response = $this->request('get', "/linode/instances/{$id}/disks");
        $this->assertSuccess($response, 'list instance disks');

        return $response->json('data') ?? [];
    }

    /**
     * The disk to image: the largest `ext4` (bootable) disk, ignoring swap/raw.
     * Returns null when the Linode has no ext4 disk (e.g. a raw/custom layout we
     * can't safely auto-image).
     */
    public function primaryDiskId(int $instanceId): ?int
    {
        $candidate = null;
        $candidateSize = -1;
        foreach ($this->getInstanceDisks($instanceId) as $disk) {
            if (! is_array($disk) || ($disk['filesystem'] ?? '') !== 'ext4') {
                continue;
            }
            $size = (int) ($disk['size'] ?? 0);
            if ($size > $candidateSize) {
                $candidate = (int) ($disk['id'] ?? 0);
                $candidateSize = $size;
            }
        }

        return $candidate ?: null;
    }

    /**
     * Create a private image from a disk. Returns the image object (notably `id`
     * like "private/12345" and `status`). Linode images are captured from a single
     * disk, not the whole instance — see {@see primaryDiskId()}.
     *
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function createImageFromDisk(int $diskId, string $label, string $description = ''): array
    {
        $body = ['disk_id' => $diskId, 'label' => $label];
        if ($description !== '') {
            $body['description'] = $description;
        }

        $response = $this->request('post', '/images', $body);
        $this->assertSuccess($response, 'create image');

        $data = $response->json();
        $image = $data['data'] ?? $data;
        if (! is_array($image) || empty($image['id'])) {
            throw new \RuntimeException('Linode API did not return an image id.');
        }

        return $image;
    }

    /**
     * Get an image by ID (e.g. "private/12345"). Notable fields: `status`
     * (creating|pending_upload|available), `size` (in MB).
     *
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function getImage(string $imageId): array
    {
        // The id contains a slash ("private/12345") that is part of the path —
        // don't url-encode it.
        $response = $this->request('get', '/images/'.ltrim($imageId, '/'));
        $this->assertSuccess($response, 'get image');

        $data = $response->json();
        $image = $data['data'] ?? $data;
        if (! is_array($image) || $image === []) {
            throw new \RuntimeException('Linode API did not return image.');
        }

        return $image;
    }

    /**
     * Poll an image until it reports `status == available`. Linode images have no
     * action-object model, so completion is read off the image's status. Returns
     * the final image array. MUST run inside a queue job — it blocks while polling.
     *
     * @param  callable(array<string, mixed>):void|null  $onTick
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function waitForImage(string $imageId, ?callable $onTick = null, int $maxAttempts = 360, int $sleepSeconds = 10): array
    {
        $image = [];
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $image = $this->getImage($imageId);
            if ($onTick !== null) {
                $onTick($image);
            }
            if ((string) ($image['status'] ?? '') === 'available') {
                return $image;
            }
            sleep($sleepSeconds);
        }

        throw new \RuntimeException('Timed out waiting for Linode image '.$imageId.' to become available.');
    }

    /**
     * Delete an image by ID. A 404 (already gone) is treated as success.
     */
    public function deleteImage(string $imageId): void
    {
        if ($imageId === '') {
            return;
        }

        $response = $this->request('delete', '/images/'.ltrim($imageId, '/'));
        if ($response->status() === 404) {
            return;
        }

        $this->assertSuccess($response, 'delete image');
    }

    /**
     * List regions (for region dropdown).
     *
     * @return array<int, array<string, mixed>>
     */
    /** @return array<string, mixed> */
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
    /** @return array<string, mixed> */
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
    /** @return array<string, mixed> */
    /**
     * @return list<array<mixed, mixed>>
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
     * @return list<array<mixed, mixed>>
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
    /** @return array<string, mixed> */
    /**
     * @return list<array<mixed, mixed>>
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
     * @return list<array<mixed, mixed>>
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
            $recordId = (int) ($existing['id']);
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
