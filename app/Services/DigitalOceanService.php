<?php

namespace App\Services;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DigitalOceanService
{
    protected string $baseUrl = 'https://api.digitalocean.com/v2';

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
     * @param  array{
     *     ipv6?: bool,
     *     backups?: bool,
     *     monitoring?: bool,
     *     vpc_uuid?: string|null,
     *     tags?: list<string>,
     *     user_data?: string
     * }  $options  Matches DigitalOcean create-droplet request body (subset).
     */
    public function createDroplet(
        string $name,
        string $region,
        string $size,
        string|int $image,
        array $sshKeyIds = [],
        array $options = []
    ): array {
        $ipv6 = (bool) ($options['ipv6'] ?? false);
        $backups = (bool) ($options['backups'] ?? false);
        $monitoring = (bool) ($options['monitoring'] ?? false);
        $userData = (string) ($options['user_data'] ?? '');
        $rawVpc = $options['vpc_uuid'] ?? null;
        $vpcUuid = is_string($rawVpc) ? trim($rawVpc) : '';
        $tags = $options['tags'] ?? [];
        $tags = is_array($tags) ? array_values(array_filter($tags, static fn ($t) => is_string($t) && $t !== '')) : [];

        $body = [
            'name' => $name,
            'region' => $region,
            'size' => $size,
            'image' => is_numeric($image) ? (int) $image : (string) $image,
            'backups' => $backups,
            'ipv6' => $ipv6,
            'monitoring' => $monitoring,
        ];
        if ($sshKeyIds !== []) {
            $body['ssh_keys'] = $sshKeyIds;
        }
        if ($userData !== '') {
            $body['user_data'] = $userData;
        }
        if ($vpcUuid !== '') {
            $body['vpc_uuid'] = $vpcUuid;
        }
        if ($tags !== []) {
            $body['tags'] = $tags;
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

    protected function request(string $method, string $path, array $bodyOrQuery = []): Response
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

    protected function assertSuccess(Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('message') ?? $response->json('error') ?? $response->body() ?: $response->reason();

        throw new \RuntimeException("DigitalOcean API failed to {$action}: {$message}");
    }
}
