<?php

namespace App\Services;

use App\Models\ProviderCredential;
use Illuminate\Support\Facades\Http;

class ScalewayService
{
    protected string $baseUrl = 'https://api.scaleway.com/instance/v1';

    protected string $token;

    protected string $projectId;

    public function __construct(ProviderCredential $credential)
    {
        $token = $credential->getApiToken();
        if (empty($token)) {
            throw new \InvalidArgumentException('Scaleway API token is required.');
        }
        $this->token = $token;
        $creds = $credential->credentials ?? [];
        $this->projectId = (string) ($creds['project_id'] ?? '');
        if ($this->projectId === '') {
            throw new \InvalidArgumentException('Scaleway project ID is required.');
        }
    }

    /**
     * Create a server. Optionally pass tags e.g. AUTHORIZED_KEY=<public_key> for SSH.
     *
     * @param  array<string>  $tags
     */
    public function createServer(
        string $zone,
        string $name,
        string $commercialType,
        string $image,
        int $volumeSizeBytes = 300000000000,
        string $volumeType = 'l_ssd',
        array $tags = []
    ): string {
        $body = [
            'name' => $name,
            'project' => $this->projectId,
            'commercial_type' => $commercialType,
            'image' => $image,
            'volumes' => [
                '0' => [
                    'size' => $volumeSizeBytes,
                    'volume_type' => $volumeType,
                ],
            ],
        ];
        if ($tags !== []) {
            $body['tags'] = $tags;
        }

        $response = $this->request('post', "/zones/{$zone}/servers", $body);
        $this->assertSuccess($response, 'create server');

        $data = $response->json();
        $server = $data['server'] ?? $data;
        $id = $server['id'] ?? $data['id'] ?? null;
        if (empty($id)) {
            throw new \RuntimeException('Scaleway API did not return server id.');
        }

        return (string) $id;
    }

    /**
     * Get server by ID (zone required in path).
     */
    public function getServer(string $zone, string $id): array
    {
        $response = $this->request('get', "/zones/{$zone}/servers/{$id}");
        $this->assertSuccess($response, 'get server');

        $data = $response->json();
        $server = $data['server'] ?? $data;
        if (empty($server)) {
            throw new \RuntimeException('Scaleway API did not return server.');
        }

        return $server;
    }

    /**
     * Get public IPv4 from server.
     */
    public static function getPublicIp(array $server): ?string
    {
        $ip = $server['public_ip'] ?? $server['public_ip_address'] ?? null;
        if (is_string($ip) && $ip !== '') {
            return $ip;
        }
        $addrs = $server['ip_addresses'] ?? [];
        foreach ($addrs as $addr) {
            if (($addr['private'] ?? true) === false) {
                $a = $addr['address'] ?? $addr['ip'] ?? null;
                if (is_string($a)) {
                    return $a;
                }
            }
        }

        return null;
    }

    /**
     * Destroy server by ID.
     */
    public function destroyServer(string $zone, string $id): void
    {
        $response = $this->request('delete', "/zones/{$zone}/servers/{$id}");
        $this->assertSuccess($response, 'delete server');
    }

    /**
     * List zones (static list from docs).
     *
     * @return array<int, array<string, string>>
     */
    public function getZones(): array
    {
        return [
            ['id' => 'fr-par-1', 'name' => 'Paris 1'],
            ['id' => 'fr-par-2', 'name' => 'Paris 2'],
            ['id' => 'fr-par-3', 'name' => 'Paris 3'],
            ['id' => 'nl-ams-1', 'name' => 'Amsterdam 1'],
            ['id' => 'nl-ams-2', 'name' => 'Amsterdam 2'],
            ['id' => 'nl-ams-3', 'name' => 'Amsterdam 3'],
            ['id' => 'pl-waw-1', 'name' => 'Warsaw 1'],
            ['id' => 'pl-waw-2', 'name' => 'Warsaw 2'],
            ['id' => 'pl-waw-3', 'name' => 'Warsaw 3'],
        ];
    }

    /**
     * List server types (products) for a zone.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getServerTypes(string $zone): array
    {
        $response = $this->request('get', "/zones/{$zone}/products/servers");
        $this->assertSuccess($response, 'list server types');
        $data = $response->json();
        $servers = $data['servers'] ?? $data['products'] ?? $data ?? [];

        return is_array($servers) ? $servers : [];
    }

    /**
     * Validate token (list servers with limit 1).
     */
    public function validateToken(): void
    {
        $zones = $this->getZones();
        $zone = $zones[0]['id'] ?? 'fr-par-1';
        $this->request('get', "/zones/{$zone}/servers", ['per_page' => 1]);
    }

    protected function request(string $method, string $path, array $body = []): \Illuminate\Http\Client\Response
    {
        $url = $this->baseUrl.$path;
        $request = Http::withHeaders(['X-Auth-Token' => $this->token])
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

    protected function assertSuccess(\Illuminate\Http\Client\Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->body() ?: $response->reason();

        throw new \RuntimeException("Scaleway API failed to {$action}: {$message}");
    }
}
