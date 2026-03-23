<?php

namespace App\Services;

use App\Models\ProviderCredential;
use Illuminate\Support\Facades\Http;

class DigitalOceanService
{
    protected string $baseUrl = 'https://api.digitalocean.com/v2';

    protected string $token;

    public function __construct(ProviderCredential $credential)
    {
        $token = $credential->getApiToken();
        if (empty($token)) {
            throw new \InvalidArgumentException('DigitalOcean API token is required.');
        }
        $this->token = $token;
    }

    /**
     * List all droplets.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDroplets(?string $tag = null): array
    {
        $query = $tag !== null ? ['tag_name' => $tag] : [];
        $response = $this->request('get', '/droplets', $query);
        $this->assertSuccess($response, 'list droplets');
        $data = $response->json();
        $droplets = $data['droplets'] ?? $data['data'] ?? [];

        return is_array($droplets) ? $droplets : [];
    }

    /**
     * Get a single droplet by ID. Returns decoded droplet array.
     *
     * @return array<string, mixed>
     */
    public function getDroplet(int $id): array
    {
        $response = $this->request('get', '/droplets/'.$id);
        $this->assertSuccess($response, 'get droplet');
        $data = $response->json();
        $droplet = $data['droplet'] ?? $data;
        if (empty($droplet) || ! is_array($droplet)) {
            throw new \RuntimeException('DigitalOcean API did not return droplet.');
        }

        return $droplet;
    }

    /**
     * Create a new droplet. Returns droplet array (IP may not be available immediately).
     *
     * @param  array<int|string>  $sshKeyIds  Optional DO SSH key IDs or fingerprints
     */
    public function createDroplet(
        string $name,
        string $region,
        string $size,
        string|int $image,
        array $sshKeyIds = [],
        bool $ipv6 = false,
        string $userData = ''
    ): array {
        $body = [
            'name' => $name,
            'region' => $region,
            'size' => $size,
            'image' => is_numeric($image) ? (int) $image : (string) $image,
            'backups' => false,
            'ipv6' => $ipv6,
            'monitoring' => false,
        ];
        if ($sshKeyIds !== []) {
            $body['ssh_keys'] = $sshKeyIds;
        }
        if ($userData !== '') {
            $body['user_data'] = $userData;
        }

        $response = $this->request('post', '/droplets', $body);
        $this->assertSuccess($response, 'create droplet');
        $data = $response->json();
        $droplet = $data['droplet'] ?? $data;
        if (empty($droplet) || ! is_array($droplet)) {
            throw new \RuntimeException('DigitalOcean API did not return droplet.');
        }

        return $droplet;
    }

    /**
     * Get public IPv4 from a droplet array (API response shape).
     */
    public static function getDropletPublicIp(array $droplet): ?string
    {
        $networks = $droplet['networks'] ?? [];
        if (isset($networks['v4']) && is_array($networks['v4'])) {
            foreach ($networks['v4'] as $n) {
                if (($n['type'] ?? '') === 'public') {
                    $ip = $n['ip_address'] ?? null;
                    if (is_string($ip) && $ip !== '') {
                        return $ip;
                    }
                }
            }
        }
        if (isset($networks['v6']) && is_array($networks['v6'])) {
            foreach ($networks['v6'] as $n) {
                if (($n['type'] ?? '') === 'public') {
                    $ip = $n['ip_address'] ?? null;
                    if (is_string($ip) && $ip !== '') {
                        return $ip;
                    }
                }
            }
        }
        // Legacy shape: array of network objects
        if (isset($networks[0]) && is_array($networks)) {
            foreach ($networks as $n) {
                if (($n['type'] ?? '') === 'public' && ($n['version'] ?? '') === '4') {
                    $ip = $n['ip_address'] ?? $n['ipAddress'] ?? null;
                    if (is_string($ip) && $ip !== '') {
                        return $ip;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get available regions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRegions(): array
    {
        $response = $this->request('get', '/regions');
        $this->assertSuccess($response, 'list regions');
        $data = $response->json();
        $regions = $data['regions'] ?? $data['data'] ?? [];

        return is_array($regions) ? $regions : [];
    }

    /**
     * Get available sizes (plans).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSizes(): array
    {
        $response = $this->request('get', '/sizes');
        $this->assertSuccess($response, 'list sizes');
        $data = $response->json();
        $sizes = $data['sizes'] ?? $data['data'] ?? [];

        return is_array($sizes) ? $sizes : [];
    }

    /**
     * Get available images (distributions, snapshots).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getImages(): array
    {
        $response = $this->request('get', '/images');
        $this->assertSuccess($response, 'list images');
        $data = $response->json();
        $images = $data['images'] ?? $data['data'] ?? [];

        return is_array($images) ? $images : [];
    }

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
     * Delete a droplet by ID.
     */
    public function destroyDroplet(int $id): void
    {
        $response = $this->request('delete', '/droplets/'.$id);
        $this->assertSuccess($response, 'delete droplet');
    }

    protected function request(string $method, string $path, array $bodyOrQuery = []): \Illuminate\Http\Client\Response
    {
        $url = $this->baseUrl.$path;
        $request = Http::withToken($this->token)
            ->acceptJson()
            ->contentType('application/json');

        $method = strtolower($method);
        if ($method === 'get') {
            return $request->get($url, $bodyOrQuery);
        }
        if ($method === 'post') {
            return $request->post($url, $bodyOrQuery);
        }
        if ($method === 'delete') {
            return $request->delete($url);
        }

        throw new \InvalidArgumentException("Unsupported method: {$method}");
    }

    protected function assertSuccess(\Illuminate\Http\Client\Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('message') ?? $response->json('error') ?? $response->body() ?: $response->reason();

        throw new \RuntimeException("DigitalOcean API failed to {$action}: {$message}");
    }
}
