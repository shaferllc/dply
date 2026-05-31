<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProviderCredential;
use App\Support\Cloud\GcpAccessToken;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GcpComputeService
{
    /**
     * @var list<string>
     */
    private const SCOPES = ['https://www.googleapis.com/auth/cloud-platform'];

    private const BASE_URL = 'https://compute.googleapis.com/compute/v1';

    private GcpAccessToken $accessToken;

    public function __construct(ProviderCredential $credential)
    {
        $this->accessToken = GcpAccessToken::fromCredential($credential);
    }

    public function validateCredentials(): void
    {
        $this->request('get', sprintf('/projects/%s/zones', rawurlencode($this->projectId())), ['maxResults' => 1]);
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function getZones(): array
    {
        try {
            $response = $this->request('get', sprintf('/projects/%s/zones', rawurlencode($this->projectId())));
            $items = $response->json('items');
            if (! is_array($items)) {
                return self::defaultZones();
            }

            $zones = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $id = (string) ($item['name'] ?? '');
                if ($id === '') {
                    continue;
                }
                $zones[] = [
                    'id' => $id,
                    'name' => (string) ($item['description'] ?? $id),
                ];
            }

            return $zones !== [] ? $zones : self::defaultZones();
        } catch (\Throwable) {
            return self::defaultZones();
        }
    }

    /**
     * @return list<array{id: string, name: string, memory_mb: int|null, vcpus: int|null}>
     */
    public function getMachineTypes(?string $zone = null): array
    {
        $zone = trim((string) $zone);
        if ($zone === '') {
            $zone = (string) config('services.gcp.default_zone', 'us-central1-a');
        }

        try {
            $response = $this->request(
                'get',
                sprintf('/projects/%s/zones/%s/machineTypes', rawurlencode($this->projectId()), rawurlencode($zone))
            );
            $items = $response->json('items');
            if (! is_array($items)) {
                return self::defaultMachineTypes();
            }

            $types = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $id = (string) ($item['name'] ?? '');
                if ($id === '') {
                    continue;
                }
                $types[] = [
                    'id' => $id,
                    'name' => (string) ($item['description'] ?? $id),
                    'memory_mb' => isset($item['memoryMb']) ? (int) $item['memoryMb'] : null,
                    'vcpus' => isset($item['guestCpus']) ? (int) $item['guestCpus'] : null,
                ];
            }

            return $types !== [] ? $types : self::defaultMachineTypes();
        } catch (\Throwable) {
            return self::defaultMachineTypes();
        }
    }

    public function createInstance(
        string $name,
        string $zone,
        string $machineType,
        string $sshPublicKey,
        string $sshUser,
    ): string {
        $zone = trim($zone);
        $machineType = trim($machineType);
        if ($zone === '' || $machineType === '') {
            throw new \InvalidArgumentException('GCP zone and machine type are required.');
        }

        $machineTypePath = str_starts_with($machineType, 'zones/')
            ? $machineType
            : sprintf('zones/%s/machineTypes/%s', $zone, $machineType);

        $payload = [
            'name' => $name,
            'machineType' => $machineTypePath,
            'disks' => [[
                'boot' => true,
                'autoDelete' => true,
                'initializeParams' => [
                    'sourceImage' => (string) config('services.gcp.default_image', 'projects/ubuntu-os-cloud/global/images/family/ubuntu-2404-lts-amd64'),
                    'diskType' => sprintf('zones/%s/diskTypes/pd-balanced', $zone),
                ],
            ]],
            'networkInterfaces' => [[
                'network' => 'global/networks/default',
                'accessConfigs' => [[
                    'name' => 'External NAT',
                    'type' => 'ONE_TO_ONE_NAT',
                ]],
            ]],
            'metadata' => [
                'items' => [[
                    'key' => 'ssh-keys',
                    'value' => sprintf('%s:%s', trim($sshUser), trim($sshPublicKey)),
                ]],
            ],
        ];

        $this->request(
            'post',
            sprintf('/projects/%s/zones/%s/instances', rawurlencode($this->projectId()), rawurlencode($zone)),
            [],
            $payload
        );

        return $name;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInstance(string $zone, string $instanceName): array
    {
        $response = $this->request(
            'get',
            sprintf(
                '/projects/%s/zones/%s/instances/%s',
                rawurlencode($this->projectId()),
                rawurlencode($zone),
                rawurlencode($instanceName)
            )
        );
        $instance = $response->json();

        if (! is_array($instance) || $instance === []) {
            throw new \RuntimeException('GCP did not return instance details.');
        }

        return $instance;
    }

    public static function getPublicIp(array $instance): ?string
    {
        $interfaces = $instance['networkInterfaces'] ?? null;
        if (! is_array($interfaces) || $interfaces === []) {
            return null;
        }

        $firstInterface = $interfaces[0] ?? null;
        if (! is_array($firstInterface)) {
            return null;
        }

        $configs = $firstInterface['accessConfigs'] ?? null;
        if (! is_array($configs) || $configs === []) {
            return null;
        }

        $firstConfig = $configs[0] ?? null;
        $ip = is_array($firstConfig) ? ($firstConfig['natIP'] ?? null) : null;

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    public function deleteInstance(string $zone, string $instanceName): void
    {
        $zone = trim($zone);
        $instanceName = trim($instanceName);
        if ($zone === '' || $instanceName === '') {
            return;
        }

        $response = $this->rawRequest(
            'delete',
            sprintf(
                '/projects/%s/zones/%s/instances/%s',
                rawurlencode($this->projectId()),
                rawurlencode($zone),
                rawurlencode($instanceName)
            )
        );

        if ($response->status() === 404) {
            return;
        }

        $this->assertSuccess($response, 'delete instance');
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public static function defaultZones(): array
    {
        return [
            ['id' => 'us-central1-a', 'name' => 'US Central (Iowa) A'],
            ['id' => 'us-east1-b', 'name' => 'US East (South Carolina) B'],
            ['id' => 'us-west1-a', 'name' => 'US West (Oregon) A'],
            ['id' => 'europe-west1-b', 'name' => 'Europe West (Belgium) B'],
            ['id' => 'europe-west2-a', 'name' => 'Europe West (London) A'],
            ['id' => 'asia-southeast1-a', 'name' => 'Asia Southeast (Singapore) A'],
        ];
    }

    /**
     * @return list<array{id: string, name: string, memory_mb: int|null, vcpus: int|null}>
     */
    public static function defaultMachineTypes(): array
    {
        return [
            ['id' => 'e2-micro', 'name' => 'E2 Micro (2 vCPU, 1 GB)', 'memory_mb' => 1024, 'vcpus' => 2],
            ['id' => 'e2-small', 'name' => 'E2 Small (2 vCPU, 2 GB)', 'memory_mb' => 2048, 'vcpus' => 2],
            ['id' => 'e2-medium', 'name' => 'E2 Medium (2 vCPU, 4 GB)', 'memory_mb' => 4096, 'vcpus' => 2],
            ['id' => 'e2-standard-2', 'name' => 'E2 Standard (2 vCPU, 8 GB)', 'memory_mb' => 8192, 'vcpus' => 2],
        ];
    }

    private function projectId(): string
    {
        return $this->accessToken->projectId();
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>  $body
     */
    private function request(string $method, string $path, array $query = [], array $body = []): Response
    {
        $response = $this->rawRequest($method, $path, $query, $body);
        $this->assertSuccess($response, sprintf('%s %s', strtoupper($method), $path));

        return $response;
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>  $body
     */
    private function rawRequest(string $method, string $path, array $query = [], array $body = []): Response
    {
        $request = Http::withToken($this->accessToken->token(self::SCOPES))
            ->acceptJson()
            ->contentType('application/json');

        $url = self::BASE_URL.$path;
        $method = strtolower($method);

        return match ($method) {
            'get' => $request->get($url, $query),
            'post' => $request->post($url, $body),
            'delete' => $request->delete($url),
            default => throw new \InvalidArgumentException('Unsupported GCP request method: '.$method),
        };
    }

    private function assertSuccess(Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('error.message')
            ?? $response->json('error.errors.0.message')
            ?? $response->body()
            ?? $response->reason();
        throw new \RuntimeException(sprintf('GCP Compute API failed to %s: %s', $action, trim((string) $message)));
    }
}
