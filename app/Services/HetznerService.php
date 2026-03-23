<?php

namespace App\Services;

use App\Models\ProviderCredential;
use Illuminate\Support\Facades\Http;

class HetznerService
{
    protected string $baseUrl = 'https://api.hetzner.cloud/v1';

    protected string $token;

    public function __construct(ProviderCredential $credential)
    {
        $token = $credential->getApiToken();
        if (empty($token)) {
            throw new \InvalidArgumentException('Hetzner API token is required.');
        }
        $this->token = $token;
    }

    /**
     * Create a new server (instance) and return its ID.
     *
     * @param  array<string>  $sshPublicKeys  Optional SSH public key strings to inject
     */
    public function createInstance(
        string $name,
        string $location,
        string $serverType,
        string $image,
        array $sshPublicKeys = [],
        string $userData = ''
    ): int {
        $body = [
            'name' => $name,
            'location' => $location,
            'server_type' => $serverType,
            'image' => $image,
        ];
        if ($sshPublicKeys !== []) {
            $body['ssh_keys'] = $sshPublicKeys;
        }
        if ($userData !== '') {
            $body['user_data'] = $userData;
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

    protected function request(string $method, string $path, array $body = []): \Illuminate\Http\Client\Response
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

    protected function assertSuccess(\Illuminate\Http\Client\Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('error.message') ?? $response->body() ?? $response->reason();
        throw new \RuntimeException("Hetzner API failed to {$action}: {$message}");
    }
}
