<?php

namespace App\Services;

use App\Models\ProviderCredential;
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
     * Get public IPv4 from instance (first ipv4 address).
     */
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

        $errors = $response->json('errors');
        $message = is_array($errors) && isset($errors[0]['reason']) ? $errors[0]['reason'] : $response->body();

        throw new \RuntimeException("Linode API failed to {$action}: {$message}");
    }
}
