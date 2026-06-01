<?php

namespace App\Services;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class HetznerService
{
    protected string $baseUrl = 'https://api.hetzner.cloud/v1';

    protected string $token;

    /**
     * Accepts either a customer's connected ProviderCredential (BYO servers) or a
     * raw API token (dply-managed servers provisioned on dply's own Hetzner project).
     */
    public function __construct(ProviderCredential|string $credentialOrToken)
    {
        $token = $credentialOrToken instanceof ProviderCredential
            ? $credentialOrToken->getApiToken()
            : trim($credentialOrToken);

        if (empty($token)) {
            throw new \InvalidArgumentException('Hetzner API token is required.');
        }

        $this->token = $token;
    }

    /**
     * Build a service bound to a raw API token (e.g. dply's platform Hetzner project).
     */
    public static function fromToken(string $token): self
    {
        return new self($token);
    }

    /**
     * Register an SSH public key in the Hetzner project. Returns key array with id.
     *
     * @return array<string, mixed>
     */
    public function addSshKey(string $name, string $publicKey): array
    {
        $response = $this->request('post', '/ssh_keys', [
            'name' => $name,
            'public_key' => trim($publicKey),
        ]);
        $this->assertSuccess($response, 'create SSH key');

        $data = $response->json();
        $key = $data['ssh_key'] ?? null;
        if (! is_array($key) || ! isset($key['id'])) {
            throw new \RuntimeException('Hetzner API did not return SSH key id.');
        }

        return $key;
    }

    /**
     * Create a new server (instance) and return its ID.
     *
     * @param  array<int|string>  $sshKeyIds  Hetzner SSH key IDs or names
     */
    /**
     * @param  array<int|string>  $sshKeyIds  Hetzner SSH key IDs or names
     * @param  list<int>  $firewallIds  Cloud Firewall IDs to attach at boot (atomic — no unreachable window)
     */
    public function createInstance(
        string $name,
        string $location,
        string $serverType,
        string $image,
        array $sshKeyIds = [],
        string $userData = '',
        array $firewallIds = []
    ): int {
        $body = [
            'name' => $name,
            'location' => $location,
            'server_type' => $serverType,
            'image' => $image,
        ];
        if ($sshKeyIds !== []) {
            $body['ssh_keys'] = $sshKeyIds;
        }
        if ($userData !== '') {
            $body['user_data'] = $userData;
        }
        if ($firewallIds !== []) {
            $body['firewalls'] = array_map(
                static fn ($id) => ['firewall' => (int) $id],
                array_values($firewallIds)
            );
        }

        $response = $this->request('post', '/servers', $body);
        $this->assertSuccess($response, 'create server');

        $data = $response->json();
        $server = $data['server'] ?? null;
        if (! $server || ! isset($server['id'])) {
            throw new \RuntimeException('Hetzner API did not return server id.');
        }

        return (int) $server['id'];
    }

    /**
     * Get instance (server) by ID. Returns decoded JSON server object.
     */
    public function getInstance(int $id): array
    {
        $response = $this->request('get', "/servers/{$id}");
        $this->assertSuccess($response, 'get server');

        $data = $response->json();
        $server = $data['server'] ?? null;
        if (! $server) {
            throw new \RuntimeException('Hetzner API did not return server.');
        }

        return $server;
    }

    /**
     * Get public IPv4 from a server array returned by getInstance().
     */
    public static function getPublicIp(array $server): ?string
    {
        $publicNet = $server['public_net'] ?? [];
        $ipv4 = $publicNet['ipv4'] ?? null;
        if ($ipv4 === null) {
            return null;
        }

        return $ipv4['ip'] ?? null;
    }

    /**
     * Destroy (delete) an instance by ID.
     */
    public function destroyInstance(int $id): void
    {
        $response = $this->request('delete', "/servers/{$id}");
        $this->assertSuccess($response, 'delete server');
    }

    /**
     * List locations (for region dropdown).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLocations(): array
    {
        $response = $this->request('get', '/locations');
        $this->assertSuccess($response, 'list locations');
        $data = $response->json();

        return $data['locations'] ?? [];
    }

    /**
     * List server types (sizes).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getServerTypes(): array
    {
        $response = $this->request('get', '/server_types');
        $this->assertSuccess($response, 'list server types');
        $data = $response->json();

        return $data['server_types'] ?? [];
    }

    /**
     * Validate token by listing servers (lightweight call).
     */
    public function validateToken(): void
    {
        $response = $this->request('get', '/servers', ['per_page' => 1]);
        $this->assertSuccess($response, 'validate token');
    }

    /**
     * Whether a DNS zone exists in this Hetzner Cloud project.
     */
    public function zoneExists(string $zoneName): bool
    {
        return $this->findZone($zoneName) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findZone(string $zoneName): ?array
    {
        $zoneName = strtolower(trim($zoneName));
        if ($zoneName === '') {
            return null;
        }

        $response = $this->request('get', '/zones', ['name' => $zoneName]);
        $this->assertSuccess($response, 'list zones');

        foreach ($response->json('zones') ?? [] as $zone) {
            if (! is_array($zone)) {
                continue;
            }

            $name = strtolower((string) ($zone['name'] ?? ''));
            if ($name === $zoneName) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * Create or replace an A/AAAA-style RRset and return the RRset payload.
     *
     * @return array<string, mixed>
     */
    public function upsertZoneRecord(
        string $zoneName,
        string $type,
        string $recordName,
        string $value,
        int $ttl = 60
    ): array {
        $type = strtoupper(trim($type));
        $rrName = self::normalizeRrsetName($recordName, $zoneName);
        $zoneKey = rawurlencode($zoneName);
        $rrPath = rawurlencode($rrName).'/'.$type;

        $existing = $this->getZoneRrset($zoneName, $rrName, $type);
        if ($existing !== null) {
            $response = $this->request(
                'post',
                "/zones/{$zoneKey}/rrsets/{$rrPath}/actions/set_records",
                [
                    'records' => [
                        ['value' => $value],
                    ],
                ]
            );
            $this->assertSuccess($response, 'set zone record');

            return $this->getZoneRrset($zoneName, $rrName, $type) ?? $existing;
        }

        $response = $this->request('post', "/zones/{$zoneKey}/rrsets", [
            'name' => $rrName,
            'type' => $type,
            'ttl' => $ttl,
            'records' => [
                ['value' => $value],
            ],
        ]);
        $this->assertSuccess($response, 'create zone record');

        $rrset = $response->json('rrset');
        if (! is_array($rrset) || $rrset === []) {
            throw new \RuntimeException('Hetzner API did not return an RRset.');
        }

        return $rrset;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getZoneRrset(string $zoneName, string $recordName, string $type): ?array
    {
        $type = strtoupper(trim($type));
        $rrName = self::normalizeRrsetName($recordName, $zoneName);
        $zoneKey = rawurlencode($zoneName);
        $rrPath = rawurlencode($rrName).'/'.$type;

        $response = $this->request('get', "/zones/{$zoneKey}/rrsets/{$rrPath}");

        if ($response->status() === 404) {
            return null;
        }

        $this->assertSuccess($response, 'get zone record');

        $rrset = $response->json('rrset');

        return is_array($rrset) ? $rrset : null;
    }

    public function deleteZoneRrset(string $zoneName, string $recordName, string $type): void
    {
        $type = strtoupper(trim($type));
        $rrName = self::normalizeRrsetName($recordName, $zoneName);
        $zoneKey = rawurlencode($zoneName);
        $rrPath = rawurlencode($rrName).'/'.$type;

        $response = $this->request('delete', "/zones/{$zoneKey}/rrsets/{$rrPath}");
        if ($response->status() === 404) {
            return;
        }

        $this->assertSuccess($response, 'delete zone record');
    }

    /**
     * Map dply relative record names to Hetzner RRset names (@ for apex).
     */
    public static function normalizeRrsetName(string $recordName, string $zoneName): string
    {
        $recordName = strtolower(trim($recordName));
        $zoneName = strtolower(trim($zoneName));

        if ($recordName === '' || $recordName === '@' || $recordName === $zoneName) {
            return '@';
        }

        if (str_ends_with($recordName, '.'.$zoneName)) {
            $recordName = substr($recordName, 0, -1 * (strlen($zoneName) + 1));
        }

        return $recordName !== '' ? $recordName : '@';
    }

    /**
     * Find a Cloud Firewall by exact name. Hetzner permits duplicate names, so
     * this returns the first exact match — fine for our per-server naming
     * (`dply-<server id>`), and makes provision-job retries idempotent.
     *
     * @return array<string,mixed>|null
     */
    public function findFirewallByName(string $name): ?array
    {
        $response = $this->request('get', '/firewalls', ['name' => $name]);
        $this->assertSuccess($response, 'list firewalls');

        foreach (($response->json('firewalls') ?? []) as $firewall) {
            if (($firewall['name'] ?? null) === $name) {
                return $firewall;
            }
        }

        return null;
    }

    /**
     * Create a Cloud Firewall with the given inbound rules. Returns its id.
     *
     * @param  list<array<string,mixed>>  $rules
     */
    public function createFirewall(string $name, array $rules): int
    {
        $response = $this->request('post', '/firewalls', [
            'name' => $name,
            'rules' => $rules,
        ]);
        $this->assertSuccess($response, 'create firewall');

        $id = $response->json('firewall.id');
        if ($id === null) {
            throw new \RuntimeException('Hetzner API did not return firewall id.');
        }

        return (int) $id;
    }

    /**
     * Replace the rule set on an existing firewall (idempotent on retries).
     *
     * @param  list<array<string,mixed>>  $rules
     */
    public function setFirewallRules(int $firewallId, array $rules): void
    {
        $response = $this->request('post', "/firewalls/{$firewallId}/actions/set_rules", [
            'rules' => $rules,
        ]);
        $this->assertSuccess($response, 'set firewall rules');
    }

    /**
     * Attach a firewall to an existing server. New servers attach atomically via
     * createInstance(firewallIds:) instead; this covers after-the-fact backfill.
     */
    public function applyFirewallToServer(int $firewallId, int $serverId): void
    {
        $response = $this->request('post', "/firewalls/{$firewallId}/actions/apply_to_resources", [
            'apply_to' => [[
                'type' => 'server',
                'server' => ['id' => $serverId],
            ]],
        ]);
        $this->assertSuccess($response, 'apply firewall to server');
    }

    /**
     * Delete a Cloud Firewall. A 404 (already gone) is treated as success.
     */
    public function deleteFirewall(int $firewallId): void
    {
        $response = $this->request('delete', "/firewalls/{$firewallId}");
        if ($response->status() === 404) {
            return;
        }
        $this->assertSuccess($response, 'delete firewall');
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

        $message = $response->json('error.message') ?? $response->body() ?? $response->reason();
        throw new \RuntimeException("Hetzner API failed to {$action}: {$message}");
    }
}
