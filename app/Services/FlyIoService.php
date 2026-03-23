<?php

namespace App\Services;

use App\Models\ProviderCredential;
use Illuminate\Support\Facades\Http;

class FlyIoService
{
    protected string $baseUrl;

    protected string $token;

    public function __construct(ProviderCredential $credential)
    {
        $creds = $credential->credentials ?? [];
        $this->token = (string) ($creds['api_token'] ?? $creds['token'] ?? '');
        if ($this->token === '') {
            throw new \InvalidArgumentException('Fly.io API token is required.');
        }
        $this->baseUrl = rtrim(config('services.fly_io.api_host', 'https://api.machines.dev'), '/');
    }

    /**
     * Create a Fly App (required before creating machines).
     */
    public function createApp(string $appName, string $orgSlug): void
    {
        $response = $this->request('post', '/v1/apps', [
            'app_name' => $appName,
            'org_slug' => $orgSlug,
        ]);
        $this->assertSuccess($response, 'create app');
    }

    /**
     * Create a machine in an app. Returns machine id.
     */
    public function createMachine(
        string $appName,
        string $region,
        string $image,
        string $vmSize,
        ?string $name = null
    ): string {
        $body = [
            'region' => $region,
            'config' => [
                'image' => $image,
                'vm' => [
                    'size' => $vmSize,
                ],
            ],
        ];
        if ($name !== null && $name !== '') {
            $body['name'] = $name;
        }

        $response = $this->request('post', '/v1/apps/'.urlencode($appName).'/machines', $body);
        $this->assertSuccess($response, 'create machine');

        $data = $response->json();
        $id = $data['id'] ?? $data['machine_id'] ?? null;
        if (empty($id)) {
            throw new \RuntimeException('Fly.io API did not return machine id.');
        }

        return (string) $id;
    }

    /**
     * Get machine by app name and machine id.
     */
    public function getMachine(string $appName, string $machineId): array
    {
        $response = $this->request('get', '/v1/apps/'.urlencode($appName).'/machines/'.urlencode($machineId));
        $this->assertSuccess($response, 'get machine');

        $data = $response->json();
        if (empty($data)) {
            throw new \RuntimeException('Fly.io API did not return machine.');
        }

        return $data;
    }

    /**
     * Delete a machine.
     */
    public function deleteMachine(string $appName, string $machineId, bool $force = true): void
    {
        $path = '/v1/apps/'.urlencode($appName).'/machines/'.urlencode($machineId);
        if ($force) {
            $path .= '?force=true';
        }
        $response = $this->request('delete', $path);
        $this->assertSuccess($response, 'delete machine');
    }

    /**
     * Delete a Fly App (machines should be stopped/deleted first).
     */
    public function deleteApp(string $appName, bool $force = true): void
    {
        $path = '/v1/apps/'.urlencode($appName);
        if ($force) {
            $path .= '?force=true';
        }
        $response = $this->request('delete', $path);
        $this->assertSuccess($response, 'delete app');
    }

    /**
     * List apps in org (for validation).
     */
    public function listApps(string $orgSlug): array
    {
        $response = $this->request('get', '/v1/apps?org_slug='.urlencode($orgSlug));
        $this->assertSuccess($response, 'list apps');
        $data = $response->json();
        $apps = $data['apps'] ?? $data['data'] ?? [];

        return is_array($apps) ? $apps : [];
    }

    /**
     * Validate token by listing apps (org_slug required).
     */
    public function validateToken(string $orgSlug): void
    {
        $this->listApps($orgSlug);
    }

    /**
     * Available Fly.io regions (Machines API does not expose a regions list).
     *
     * @return array<int, array{id: string, name: string}>
     */
    public static function getRegions(): array
    {
        return [
            ['id' => 'ams', 'name' => 'Amsterdam'],
            ['id' => 'arn', 'name' => 'Stockholm'],
            ['id' => 'bom', 'name' => 'Mumbai'],
            ['id' => 'cdg', 'name' => 'Paris'],
            ['id' => 'dfw', 'name' => 'Dallas'],
            ['id' => 'ewr', 'name' => 'Secaucus, NJ'],
            ['id' => 'fra', 'name' => 'Frankfurt'],
            ['id' => 'gru', 'name' => 'São Paulo'],
            ['id' => 'iad', 'name' => 'Ashburn, VA'],
            ['id' => 'jnb', 'name' => 'Johannesburg'],
            ['id' => 'lax', 'name' => 'Los Angeles'],
            ['id' => 'lhr', 'name' => 'London'],
            ['id' => 'nrt', 'name' => 'Tokyo'],
            ['id' => 'ord', 'name' => 'Chicago'],
            ['id' => 'sin', 'name' => 'Singapore'],
            ['id' => 'sjc', 'name' => 'San Jose'],
            ['id' => 'syd', 'name' => 'Sydney'],
            ['id' => 'yyz', 'name' => 'Toronto'],
        ];
    }

    /**
     * Available VM sizes for Fly Machines.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public static function getVmSizes(): array
    {
        return [
            ['id' => 'shared-cpu-1x', 'name' => 'Shared CPU 1x'],
            ['id' => 'shared-cpu-2x', 'name' => 'Shared CPU 2x'],
            ['id' => 'shared-cpu-4x', 'name' => 'Shared CPU 4x'],
            ['id' => 'performance-1x', 'name' => 'Performance 1x'],
            ['id' => 'performance-2x', 'name' => 'Performance 2x'],
            ['id' => 'performance-4x', 'name' => 'Performance 4x'],
        ];
    }

    protected function request(string $method, string $path, array $body = []): \Illuminate\Http\Client\Response
    {
        $url = $this->baseUrl.$path;
        $request = Http::withToken($this->token)
            ->acceptJson()
            ->contentType('application/json');

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
        if ($response->successful() || $response->status() === 202) {
            return;
        }

        $message = $response->json('message') ?? $response->json('error') ?? $response->body() ?: $response->reason();

        throw new \RuntimeException("Fly.io API failed to {$action}: {$message}");
    }
}
