<?php

namespace App\Services;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class EquinixMetalService
{
    protected string $baseUrl = 'https://api.equinix.com/metal/v1';

    protected string $token;

    protected string $projectId;

    public function __construct(ProviderCredential $credential)
    {
        $token = $credential->getApiToken();
        if (empty($token)) {
            throw new \InvalidArgumentException('Equinix Metal API token is required.');
        }
        $this->token = $token;
        $creds = $credential->credentials ?? [];
        $this->projectId = (string) ($creds['project_id'] ?? '');
        if ($this->projectId === '') {
            throw new \InvalidArgumentException('Equinix Metal project ID is required.');
        }
    }

    /**
     * Create an SSH key in the project and return its ID.
     */
    public function createSshKey(string $label, string $publicKey): string
    {
        $response = $this->request('post', "/projects/{$this->projectId}/ssh-keys", [
            'label' => $label,
            'key' => trim($publicKey),
        ]);
        $this->assertSuccess($response, 'create SSH key');

        $data = $response->json();
        $id = $data['id'] ?? null;
        if (empty($id)) {
            throw new \RuntimeException('Equinix Metal API did not return SSH key id.');
        }

        return (string) $id;
    }

    /**
     * Create a device and return its ID.
     */
    public function createDevice(
        string $hostname,
        string $plan,
        string $operatingSystem,
        string $metro,
        array $sshKeyIds = []
    ): string {
        $body = [
            'hostname' => $hostname,
            'plan' => $plan,
            'operating_system' => $operatingSystem,
            'metro' => $metro,
        ];
        if ($sshKeyIds !== []) {
            $body['ssh_keys'] = $sshKeyIds;
        }

        $response = $this->request('post', "/projects/{$this->projectId}/devices", $body);
        $this->assertSuccess($response, 'create device');

        $data = $response->json();
        $id = $data['id'] ?? null;
        if (empty($id)) {
            throw new \RuntimeException('Equinix Metal API did not return device id.');
        }

        return (string) $id;
    }

    /**
     * Get device by ID.
     */
    public function getDevice(string $id): array
    {
        $response = $this->request('get', '/devices/'.$id);
        $this->assertSuccess($response, 'get device');

        $data = $response->json();
        if (empty($data)) {
            throw new \RuntimeException('Equinix Metal API did not return device.');
        }

        return $data;
    }

    /**
     * Get public IPv4 from device.
     */
    public static function getPublicIp(array $device): ?string
    {
        if (! empty($device['primary_ip4']) && is_string($device['primary_ip4'])) {
            return $device['primary_ip4'];
        }
        foreach ($device['ip_addresses'] ?? [] as $addr) {
            if (($addr['public'] ?? false) && ($addr['address_family'] ?? 0) === 4) {
                $a = $addr['address'] ?? null;
                if (is_string($a)) {
                    return $a;
                }
            }
        }

        return null;
    }

    /**
     * Destroy device by ID.
     */
    public function destroyDevice(string $id): void
    {
        $response = $this->request('delete', '/devices/'.$id);
        $this->assertSuccess($response, 'delete device');
    }

    /**
     * List plans.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPlans(): array
    {
        $response = $this->request('get', '/plans');
        $this->assertSuccess($response, 'list plans');
        $data = $response->json();

        return $data['plans'] ?? $data ?? [];
    }

    /**
     * List metros (and/or facilities). Prefer metros for create.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMetros(): array
    {
        $response = $this->request('get', '/metros');
        $this->assertSuccess($response, 'list metros');
        $data = $response->json();

        return $data['metros'] ?? $data ?? [];
    }

    /**
     * Validate token (GET user or project).
     */
    public function validateToken(): void
    {
        $response = $this->request('get', '/projects/'.$this->projectId);
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

        throw new \RuntimeException("Equinix Metal API failed to {$action}: {$message}");
    }
}
