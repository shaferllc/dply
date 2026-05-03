<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProviderCredential;
use Aws\AppRunner\AppRunnerClient;

/**
 * Thin wrapper around AWS App Runner. Used by the dply edge layer
 * to provision/redeploy/teardown container apps on AWS.
 *
 * Why App Runner over ECS Fargate or Lightsail Containers:
 * - cheapest managed container path on AWS (no LB / no NAT
 *   gateway / no Fargate task minimums for spiky workloads)
 * - built-in HTTPS + custom domain support
 * - public ECR / Docker Hub image support without extra IAM
 * - auto-scaling out of the box
 *
 * AWS region is set at credential-creation time (see
 * ManagesProviderCredentials::storeAwsAppRunner) and threaded
 * through to the client constructor here.
 */
class AwsAppRunnerService
{
    protected AppRunnerClient $client;

    protected string $region;

    public function __construct(ProviderCredential $credential, ?string $region = null)
    {
        $creds = $credential->credentials ?? [];
        $key = (string) ($creds['access_key_id'] ?? '');
        $secret = (string) ($creds['secret_access_key'] ?? '');
        if ($key === '' || $secret === '') {
            throw new \InvalidArgumentException('AWS access key ID and secret access key are required.');
        }
        $this->region = $region ?? (string) ($creds['region'] ?? 'us-east-1');
        $this->client = new AppRunnerClient([
            'region' => $this->region,
            'version' => 'latest',
            'credentials' => [
                'key' => $key,
                'secret' => $secret,
            ],
        ]);
    }

    /**
     * Create an App Runner service from a public container image.
     *
     * Private ECR / DockerHub auth is supported via $authConfigArn —
     * pass null for public images. The 'cpu' and 'memory' values
     * are App Runner's standard slugs (e.g. 0.25 vCPU / 0.5 GB =
     * "256" / "512").
     *
     * @param  array<string, string>  $envVars
     * @return array{service_arn: string, service_url: ?string}
     */
    public function createService(
        string $serviceName,
        string $image,
        int $port,
        array $envVars = [],
        ?string $authConfigArn = null,
        string $cpu = '256',
        string $memory = '512',
    ): array {
        $sourceConfig = [
            'ImageRepository' => [
                'ImageIdentifier' => $image,
                'ImageRepositoryType' => str_starts_with($image, 'public.ecr.aws/')
                    ? 'ECR_PUBLIC'
                    : (str_contains($image, '.dkr.ecr.') ? 'ECR' : 'ECR_PUBLIC'),
                'ImageConfiguration' => [
                    'Port' => (string) $port,
                    'RuntimeEnvironmentVariables' => $envVars,
                ],
            ],
            'AutoDeploymentsEnabled' => false,
        ];
        if ($authConfigArn !== null) {
            $sourceConfig['AuthenticationConfiguration'] = ['AccessRoleArn' => $authConfigArn];
        }

        $result = $this->client->createService([
            'ServiceName' => $serviceName,
            'SourceConfiguration' => $sourceConfig,
            'InstanceConfiguration' => [
                'Cpu' => $cpu,
                'Memory' => $memory,
            ],
        ]);

        $service = $result['Service'] ?? [];

        return [
            'service_arn' => (string) ($service['ServiceArn'] ?? ''),
            'service_url' => isset($service['ServiceUrl']) ? 'https://'.$service['ServiceUrl'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function describeService(string $serviceArn): array
    {
        $result = $this->client->describeService(['ServiceArn' => $serviceArn]);

        return $result['Service'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listServices(): array
    {
        $result = $this->client->listServices();
        $services = $result['ServiceSummaryList'] ?? [];

        return is_array($services) ? array_values($services) : [];
    }

    /**
     * Trigger a new deployment using the existing source config.
     * For images on public registries, this re-pulls the tag (so
     * "v1.2.3" → "v1.2.4" requires updateService first; "latest"
     * just re-pulls).
     *
     * @return array{operation_id: string}
     */
    public function startDeployment(string $serviceArn): array
    {
        $result = $this->client->startDeployment(['ServiceArn' => $serviceArn]);

        return [
            'operation_id' => (string) ($result['OperationId'] ?? ''),
        ];
    }

    /**
     * Patch the service's source image (for image tag bumps).
     *
     * @param  array<string, string>  $envVars
     */
    public function updateImage(string $serviceArn, string $image, int $port, array $envVars = []): void
    {
        $this->client->updateService([
            'ServiceArn' => $serviceArn,
            'SourceConfiguration' => [
                'ImageRepository' => [
                    'ImageIdentifier' => $image,
                    'ImageRepositoryType' => str_starts_with($image, 'public.ecr.aws/')
                        ? 'ECR_PUBLIC'
                        : (str_contains($image, '.dkr.ecr.') ? 'ECR' : 'ECR_PUBLIC'),
                    'ImageConfiguration' => [
                        'Port' => (string) $port,
                        'RuntimeEnvironmentVariables' => $envVars,
                    ],
                ],
            ],
        ]);
    }

    public function deleteService(string $serviceArn): void
    {
        $this->client->deleteService(['ServiceArn' => $serviceArn]);
    }

    /**
     * Cheap auth probe — listServices is read-only and returns
     * fast even on empty accounts. Throws on auth failure.
     */
    public function validateCredentials(): void
    {
        $this->client->listServices(['MaxResults' => 1]);
    }

    /**
     * Eight regions where App Runner is generally available, ordered
     * roughly by cost (cheapest first). Mirrors the official AWS
     * regional availability matrix.
     *
     * @return list<array{slug: string, label: string}>
     */
    public static function getRegions(): array
    {
        return [
            ['slug' => 'us-east-1', 'label' => 'N. Virginia (us-east-1)'],
            ['slug' => 'us-west-2', 'label' => 'Oregon (us-west-2)'],
            ['slug' => 'us-east-2', 'label' => 'Ohio (us-east-2)'],
            ['slug' => 'eu-west-1', 'label' => 'Ireland (eu-west-1)'],
            ['slug' => 'eu-central-1', 'label' => 'Frankfurt (eu-central-1)'],
            ['slug' => 'ap-northeast-1', 'label' => 'Tokyo (ap-northeast-1)'],
            ['slug' => 'ap-southeast-1', 'label' => 'Singapore (ap-southeast-1)'],
            ['slug' => 'ap-southeast-2', 'label' => 'Sydney (ap-southeast-2)'],
        ];
    }

    /**
     * Replace the underlying AppRunnerClient — used by tests so
     * they can inject a Mockery double without the full SDK
     * credential dance.
     */
    public function withClient(AppRunnerClient $client): self
    {
        $this->client = $client;

        return $this;
    }
}
