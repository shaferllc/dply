<?php

namespace App\Services;

use App\Models\ProviderCredential;
use Aws\Ec2\Ec2Client;

class AwsEc2Service
{
    protected Ec2Client $client;

    protected string $region;

    protected ProviderCredential $credential;

    public function __construct(ProviderCredential $credential, ?string $region = null)
    {
        $this->credential = $credential;
        $creds = $credential->credentials ?? [];
        $key = (string) ($creds['access_key_id'] ?? '');
        $secret = (string) ($creds['secret_access_key'] ?? '');
        if ($key === '' || $secret === '') {
            throw new \InvalidArgumentException('AWS access key ID and secret access key are required.');
        }
        $this->region = $region ?? (string) ($creds['region'] ?? config('services.aws.default_region', 'us-east-1'));
        $this->client = new Ec2Client([
            'region' => $this->region,
            'version' => 'latest',
            'credentials' => [
                'key' => $key,
                'secret' => $secret,
            ],
        ]);
    }

    /**
     * Create an EC2 key pair. Returns key name and private key material.
     *
     * @return array{key_name: string, key_material: string}
     */
    public function createKeyPair(string $keyName): array
    {
        $result = $this->client->createKeyPair([
            'KeyName' => $keyName,
        ]);

        return [
            'key_name' => $result['KeyName'],
            'key_material' => (string) $result['KeyMaterial'],
        ];
    }

    /**
     * Run an EC2 instance in the client's region. Returns instance ID.
     */
    public function runInstances(
        string $imageId,
        string $instanceType,
        string $keyName,
        ?string $nameTag = null
    ): string {
        $params = [
            'ImageId' => $imageId,
            'InstanceType' => $instanceType,
            'MinCount' => 1,
            'MaxCount' => 1,
            'KeyName' => $keyName,
        ];
        if ($nameTag !== null && $nameTag !== '') {
            $params['TagSpecifications'] = [
                [
                    'ResourceType' => 'instance',
                    'Tags' => [
                        ['Key' => 'Name', 'Value' => $nameTag],
                    ],
                ],
            ];
        }

        $result = $this->client->runInstances($params);
        $instances = $result['Instances'] ?? [];
        $first = reset($instances);
        $id = $first['InstanceId'] ?? null;
        if (empty($id)) {
            throw new \RuntimeException('AWS EC2 did not return instance ID.');
        }

        return (string) $id;
    }

    /**
     * Describe instance(s) by ID. Returns first reservation's instances.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describeInstances(string $instanceId): array
    {
        $result = $this->client->describeInstances([
            'InstanceIds' => [$instanceId],
        ]);
        $reservations = $result['Reservations'] ?? [];
        $first = reset($reservations);
        $instances = $first['Instances'] ?? [];

        return $instances;
    }

    /**
     * Get public IPv4 from an instance array (first instance).
     */
    public static function getPublicIp(array $instances): ?string
    {
        $instance = reset($instances);
        if (! is_array($instance)) {
            return null;
        }
        $ip = $instance['PublicIpAddress'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    /**
     * Get instance state (e.g. 'running', 'pending').
     */
    public static function getState(array $instances): ?string
    {
        $instance = reset($instances);
        if (! is_array($instance)) {
            return null;
        }
        $state = $instance['State']['Name'] ?? null;

        return is_string($state) ? $state : null;
    }

    /**
     * Terminate instance(s).
     */
    public function terminateInstances(string $instanceId): void
    {
        $this->client->terminateInstances([
            'InstanceIds' => [$instanceId],
        ]);
    }

    /**
     * Delete a key pair by name.
     */
    public function deleteKeyPair(string $keyName): void
    {
        $this->client->deleteKeyPair([
            'KeyName' => $keyName,
        ]);
    }

    /**
     * List regions (for create form).
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function getRegions(): array
    {
        $result = $this->client->describeRegions([
            'AllRegions' => false,
        ]);
        $regions = $result['Regions'] ?? [];
        $out = [];
        foreach ($regions as $r) {
            $regionName = $r['RegionName'] ?? null;
            if (is_string($regionName) && $regionName !== '') {
                $out[] = [
                    'id' => $regionName,
                    'name' => $regionName,
                ];
            }
        }

        if ($out === []) {
            return self::getDefaultRegions();
        }

        return $out;
    }

    /**
     * Default region list when API is unavailable (e.g. permission or network).
     *
     * @return array<int, array{id: string, name: string}>
     */
    public static function getDefaultRegions(): array
    {
        return [
            ['id' => 'us-east-1', 'name' => 'us-east-1 (N. Virginia)'],
            ['id' => 'us-east-2', 'name' => 'us-east-2 (Ohio)'],
            ['id' => 'us-west-1', 'name' => 'us-west-1 (N. California)'],
            ['id' => 'us-west-2', 'name' => 'us-west-2 (Oregon)'],
            ['id' => 'eu-west-1', 'name' => 'eu-west-1 (Ireland)'],
            ['id' => 'eu-central-1', 'name' => 'eu-central-1 (Frankfurt)'],
            ['id' => 'ap-northeast-1', 'name' => 'ap-northeast-1 (Tokyo)'],
            ['id' => 'ap-southeast-1', 'name' => 'ap-southeast-1 (Singapore)'],
        ];
    }

    /**
     * Common instance types for the create form.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public static function getInstanceTypes(): array
    {
        return [
            ['id' => 't3.micro', 'name' => 't3.micro (1 vCPU, 1 GB)'],
            ['id' => 't3.small', 'name' => 't3.small (2 vCPU, 2 GB)'],
            ['id' => 't3.medium', 'name' => 't3.medium (2 vCPU, 4 GB)'],
            ['id' => 't3.large', 'name' => 't3.large (2 vCPU, 8 GB)'],
            ['id' => 't3.xlarge', 'name' => 't3.xlarge (4 vCPU, 16 GB)'],
            ['id' => 't2.micro', 'name' => 't2.micro (1 vCPU, 1 GB)'],
            ['id' => 't2.small', 'name' => 't2.small (1 vCPU, 2 GB)'],
            ['id' => 't2.medium', 'name' => 't2.medium (2 vCPU, 4 GB)'],
        ];
    }

    /**
     * Validate credentials (describe regions).
     */
    public function validateCredentials(): void
    {
        $this->client->describeRegions(['AllRegions' => false]);
    }
}
