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

    public function provisionFromSource(Site $site, ProviderCredential $credential): array
    {
        $source = $this->sourceSpec($site);
        $connectionArn = $this->connectionArn($credential);

        $service = new AwsAppRunnerService($credential, $site->container_region ?: null);
        $result = $service->createServiceFromSource(
            serviceName: $this->backendServiceName($site),
            repositoryUrl: $source['repository_url'],
            branch: $source['branch'],
            connectionArn: $connectionArn,
            port: (int) ($site->container_port ?: 8080),
            envVars: $this->siteEnvVars($site),
            buildEnvVars: $this->siteBuildEnvVars($site),
            dockerfilePath: $source['dockerfile_path'],
            autoDeploy: $source['deploy_on_push'],
        );

        return [
            'backend_id' => $result['service_arn'],
            'live_url' => $result['service_url'],
        ];
    }

    /**
     * @return array{repository_url: string, branch: string, dockerfile_path: ?string, deploy_on_push: bool}
     */
    private function sourceSpec(Site $site): array
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $source = $meta['container']['source'] ?? [];
        if (! is_array($source) || ! is_string($source['repo'] ?? null) || $source['repo'] === '') {
            throw new \RuntimeException('Site has no container source spec recorded — cannot provision from source.');
        }

        $repo = (string) $source['repo'];
        $repositoryUrl = str_starts_with($repo, 'http') ? $repo : 'https://github.com/'.$repo;

        return [
            'repository_url' => $repositoryUrl,
            'branch' => is_string($source['branch'] ?? null) && $source['branch'] !== '' ? (string) $source['branch'] : 'main',
            'dockerfile_path' => is_string($source['dockerfile_path'] ?? null) && $source['dockerfile_path'] !== '' ? (string) $source['dockerfile_path'] : null,
            'deploy_on_push' => (bool) ($source['deploy_on_push'] ?? true),
        ];
    }

    private function connectionArn(ProviderCredential $credential): string
    {
        $arn = $credential->credentials['github_connection_arn'] ?? null;
        if (! is_string($arn) || $arn === '') {
            throw new \RuntimeException('AWS App Runner credential has no github_connection_arn — connect a GitHub App in App Runner first.');
        }

        return $arn;
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

    public function updateEnvVars(Site $site, ProviderCredential $credential): void
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return;
        }

        $service = new AwsAppRunnerService($credential, $site->container_region ?: null);
        $envVars = $this->siteEnvVars($site);

        if (is_array($site->meta['container']['source'] ?? null)) {
            // Source mode — re-push the CodeRepository spec with the
            // new runtime + build env vars while keeping repo / branch / dockerfile.
            $source = $this->sourceSpec($site);
            $service->updateServiceSourceEnv(
                serviceArn: $site->container_backend_id,
                repositoryUrl: $source['repository_url'],
                branch: $source['branch'],
                connectionArn: $this->connectionArn($credential),
                port: (int) ($site->container_port ?: 8080),
                envVars: $envVars,
                buildEnvVars: $this->siteBuildEnvVars($site),
                dockerfilePath: $source['dockerfile_path'],
            );

            return;
        }

        // Image mode — updateImage already re-pushes env vars alongside
        // the (unchanged) image.
        if (is_string($site->container_image) && $site->container_image !== '') {
            $service->updateImage(
                $site->container_backend_id,
                $site->container_image,
                (int) ($site->container_port ?: 8080),
                $envVars,
            );
        }
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
        return $this->parseEnvLines((string) ($site->env_file_content ?? ''));
    }

    /**
     * Build-time env vars are stored separately on the Site's meta
     * under meta.container.build_env_file_content. They map to App
     * Runner's BuildEnvironmentVariables — needed for app secrets
     * (e.g. private package tokens) that the build needs but
     * shouldn't leak into runtime.
     *
     * @return array<string, string>
     */
    private function siteBuildEnvVars(Site $site): array
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $content = $meta['container']['build_env_file_content'] ?? '';

        return $this->parseEnvLines(is_string($content) ? $content : '');
    }

    /**
     * Desired instance count for the site. AWS App Runner uses
     * AutoScalingConfiguration ARNs for full scaling control; this
     * value is the "intent" the operator sets via dply:edge:scale,
     * surfaced in the dashboard / CLI even when the live App Runner
     * config differs.
     */
    private function siteInstanceCount(Site $site): int
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $raw = $meta['container']['instance_count'] ?? null;

        return is_int($raw) && $raw > 0 ? $raw : 1;
    }

    /**
     * @return array<string, string>
     */
    private function parseEnvLines(string $envContent): array
    {
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
