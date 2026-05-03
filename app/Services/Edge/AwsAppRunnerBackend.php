<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Services\AwsAppRunnerService;

class AwsAppRunnerBackend implements EdgeBackend
{
    public function providerKey(): string
    {
        return 'aws_app_runner';
    }

    public function provision(Site $site, ProviderCredential $credential): array
    {
        $service = new AwsAppRunnerService($credential, $site->container_region ?: null);
        $result = $service->createService(
            serviceName: $this->backendServiceName($site),
            image: (string) $site->container_image,
            port: (int) ($site->container_port ?: 8080),
            envVars: $this->siteEnvVars($site),
        );

        return [
            'backend_id' => $result['service_arn'],
            'live_url' => $result['service_url'],
        ];
    }

    public function redeploy(Site $site, ProviderCredential $credential): array
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return ['deployment_id' => null];
        }

        $service = new AwsAppRunnerService($credential, $site->container_region ?: null);
        $result = $service->startDeployment($site->container_backend_id);

        return ['deployment_id' => $result['operation_id']];
    }

    public function updateImage(Site $site, ProviderCredential $credential, string $image): void
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return;
        }

        (new AwsAppRunnerService($credential, $site->container_region ?: null))->updateImage(
            $site->container_backend_id,
            $image,
            (int) ($site->container_port ?: 8080),
            $this->siteEnvVars($site),
        );
    }

    public function teardown(Site $site, ProviderCredential $credential): void
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return;
        }

        try {
            (new AwsAppRunnerService($credential, $site->container_region ?: null))
                ->deleteService($site->container_backend_id);
        } catch (\Throwable) {
            // Idempotent.
        }
    }

    public function inspect(Site $site, ProviderCredential $credential): array
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return ['phase' => 'unknown', 'live_url' => null, 'raw' => []];
        }

        $svc = (new AwsAppRunnerService($credential, $site->container_region ?: null))
            ->describeService($site->container_backend_id);
        $url = isset($svc['ServiceUrl']) ? 'https://'.$svc['ServiceUrl'] : null;

        return [
            'phase' => (string) ($svc['Status'] ?? 'unknown'),
            'live_url' => $url,
            'raw' => $svc,
        ];
    }

    public function regions(): array
    {
        return AwsAppRunnerService::getRegions();
    }

    public function attachDomain(Site $site, ProviderCredential $credential, string $hostname): array
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return [];
        }

        return (new AwsAppRunnerService($credential, $site->container_region ?: null))
            ->associateCustomDomain($site->container_backend_id, $hostname);
    }

    public function detachDomain(Site $site, ProviderCredential $credential, string $hostname): void
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return;
        }
        try {
            (new AwsAppRunnerService($credential, $site->container_region ?: null))
                ->disassociateCustomDomain($site->container_backend_id, $hostname);
        } catch (\Throwable) {
            // Idempotent — already gone is fine.
        }
    }

    /**
     * @return array<string, string>
     */
    private function siteEnvVars(Site $site): array
    {
        $envContent = (string) ($site->env_file_content ?? '');
        if ($envContent === '') {
            return [];
        }
        $vars = [];
        foreach (explode("\n", $envContent) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1), " \t\"'");
            if ($key !== '') {
                $vars[$key] = $value;
            }
        }

        return $vars;
    }

    private function backendServiceName(Site $site): string
    {
        // App Runner service names: alnum + hyphen + underscore,
        // 4–40 chars, must start with a letter.
        $name = preg_replace('/[^a-z0-9_-]/i', '-', strtolower($site->slug ?: $site->name ?: 'dply-app'));
        $name = trim((string) $name, '-_');
        if (! preg_match('/^[a-z]/', $name)) {
            $name = 'dply-'.$name;
        }
        $name = substr($name, 0, 40);
        if (strlen($name) < 4) {
            $name = 'dply-'.$name;
        }

        return $name;
    }
}
