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

        $error = $response->json('error');
        $message = is_string($error) ? $error : ($response->body() ?: $response->reason());

        throw new \RuntimeException("Vultr API failed to {$action}: {$message}");
    }
}
