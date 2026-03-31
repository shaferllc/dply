<?php

namespace App\Services;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class UpCloudService
{
    protected string $baseUrl = 'https://api.upcloud.com/1.3';

    protected string $username;

    protected string $password;

    public function __construct(ProviderCredential $credential)
    {
        $creds = $credential->credentials ?? [];
        $this->username = (string) ($creds['api_username'] ?? $creds['username'] ?? '');
        $this->password = (string) ($creds['api_password'] ?? $creds['password'] ?? '');
        if ($this->username === '' || $this->password === '') {
            throw new \InvalidArgumentException('UpCloud API username and password are required.');
        }
    }

    /**
     * Create a server from template with SSH keys. Returns server UUID.
     */
    public function createServer(
        string $zone,
        string $plan,
        string $title,
        string $hostname,
        string $templateStorageUuid,
        array $sshPublicKeys = [],
        int $storageSizeGb = 25
    ): string {
        $storageDevice = [
            'action' => 'clone',
            'storage' => $templateStorageUuid,
            'title' => $title.'-disk',
            'size' => $storageSizeGb,
            'tier' => 'maxiops',
        ];
        $body = [
            'server' => [
                'zone' => $zone,
                'title' => $title,
                'hostname' => $hostname,
                'plan' => $plan,
                'storage_devices' => [
                    'storage_device' => [$storageDevice],
                ],
            ],
        ];
        if ($sshPublicKeys !== []) {
            $body['server']['login_user'] = [
                'username' => 'root',
                'ssh_keys' => [
                    'ssh_key' => $sshPublicKeys,
                ],
            ];
        }

        $response = $this->request('post', '/server', $body);
        $this->assertSuccess($response, 'create server');

        $data = $response->json();
        $server = $data['server'] ?? $data;
        $uuid = $server['uuid'] ?? $data['uuid'] ?? null;
        if (empty($uuid)) {
            throw new \RuntimeException('UpCloud API did not return server UUID.');
        }

        return (string) $uuid;
    }

    /**
     * Get server by UUID.
     */
    public function getServer(string $uuid): array
    {
        $response = $this->request('get', '/server/'.$uuid);
        $this->assertSuccess($response, 'get server');

        $data = $response->json();
        $server = $data['server'] ?? $data;
        if (empty($server)) {
            throw new \RuntimeException('UpCloud API did not return server.');
        }

        return $server;
    }

    /**
     * Get public IPv4 from server.
     */
    public static function getPublicIp(array $server): ?string
    {
        $addrs = $server['ip_addresses']['ip_address'] ?? [];
        if (! is_array($addrs)) {
            return null;
        }
        foreach ($addrs as $addr) {
            if (($addr['access'] ?? '') === 'public' && ($addr['family'] ?? '') === 'IPv4') {
                $a = $addr['address'] ?? null;
                if (is_string($a) && $a !== '') {
                    return $a;
                }
            }
        }

        return null;
    }

    /**
     * Stop and delete server by UUID.
     */
    public function destroyServer(string $uuid): void
    {
        $response = $this->request('delete', '/server/'.$uuid);
        $this->assertSuccess($response, 'delete server');
    }

    /**
     * List zones.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getZones(): array
    {
        $response = $this->request('get', '/zone');
        $this->assertSuccess($response, 'list zones');
        $data = $response->json();
        $zones = $data['zones']['zone'] ?? $data['zones'] ?? $data ?? [];

        return is_array($zones) ? $zones : [];
    }

    /**
     * List plans.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPlans(): array
    {
        $response = $this->request('get', '/plan');
        $this->assertSuccess($response, 'list plans');
        $data = $response->json();
        $plans = $data['plans']['plan'] ?? $data['plans'] ?? $data ?? [];

        return is_array($plans) ? $plans : [];
    }

    /**
     * List storage templates (for OS selection).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTemplates(): array
    {
        $response = $this->request('get', '/storage/template');
        $this->assertSuccess($response, 'list templates');
        $data = $response->json();
        $storages = $data['storages']['storage'] ?? $data['storages'] ?? $data ?? [];

        return is_array($storages) ? $storages : [];
    }

    /**
     * Validate credentials (list zones).
     */
    public function validateToken(): void
    {
        $response = $this->request('get', '/zone');
        $this->assertSuccess($response, 'validate credentials');
    }

    protected function request(string $method, string $path, array $body = []): Response
    {
        $url = $this->baseUrl.$path;
        $request = Http::withBasicAuth($this->username, $this->password)
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

        throw new \RuntimeException("UpCloud API failed to {$action}: {$message}");
    }
}
