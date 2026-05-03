<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Services\DigitalOceanAppPlatformService;

class DigitalOceanAppPlatformBackend implements EdgeBackend
{
    public function providerKey(): string
    {
        return 'digitalocean_app_platform';
    }

    public function provision(Site $site, ProviderCredential $credential): array
    {
        $service = new DigitalOceanAppPlatformService($credential);

        $env = $this->siteEnvVars($site);
        $buildEnv = $this->siteBuildEnvVars($site);
        $result = $service->createApp(
            appName: $this->backendAppName($site),
            region: $site->container_region ?: 'nyc',
            image: (string) $site->container_image,
            port: (int) ($site->container_port ?: 8080),
            envVars: $env,
            buildEnvVars: $buildEnv,
            instanceCount: $this->siteInstanceCount($site),
        );

        return [
            'backend_id' => $result['id'],
            'live_url' => $result['default_ingress'],
        ];
    }

    public function provisionFromSource(Site $site, ProviderCredential $credential): array
    {
        $service = new DigitalOceanAppPlatformService($credential);
        $source = $this->sourceSpec($site);

        $result = $service->createAppFromSource(
            appName: $this->backendAppName($site),
            region: $site->container_region ?: 'nyc',
            repo: $source['repo'],
            branch: $source['branch'],
            port: (int) ($site->container_port ?: 8080),
            deployOnPush: $source['deploy_on_push'],
            dockerfilePath: $source['dockerfile_path'],
            envVars: $this->siteEnvVars($site),
            buildEnvVars: $this->siteBuildEnvVars($site),
            instanceCount: $this->siteInstanceCount($site),
        );

        return [
            'backend_id' => $result['id'],
            'live_url' => $result['default_ingress'],
        ];
    }

    /**
     * @return array{repo: string, branch: string, dockerfile_path: ?string, deploy_on_push: bool}
     */
    private function sourceSpec(Site $site): array
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $source = $meta['container']['source'] ?? [];
        if (! is_array($source) || ! is_string($source['repo'] ?? null) || $source['repo'] === '') {
            throw new \RuntimeException('Site has no container source spec recorded — cannot provision from source.');
        }

        return [
            'repo' => (string) $source['repo'],
            'branch' => is_string($source['branch'] ?? null) && $source['branch'] !== '' ? (string) $source['branch'] : 'main',
            'dockerfile_path' => is_string($source['dockerfile_path'] ?? null) && $source['dockerfile_path'] !== '' ? (string) $source['dockerfile_path'] : null,
            'deploy_on_push' => (bool) ($source['deploy_on_push'] ?? true),
        ];
    }

    public function redeploy(Site $site, ProviderCredential $credential): array
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return ['deployment_id' => null];
        }

        $service = new DigitalOceanAppPlatformService($credential);
        $result = $service->deployApp($site->container_backend_id, force: false);

        return ['deployment_id' => $result['id']];
    }

    public function updateImage(Site $site, ProviderCredential $credential, string $image): void
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return;
        }

        $service = new DigitalOceanAppPlatformService($credential);
        $current = $service->getApp($site->container_backend_id);
        $spec = $current['spec'] ?? [];
        if (! is_array($spec) || empty($spec['services'][0])) {
            // Spec shape unexpected — fall back to redeploy without
            // image change so the operator at least sees a roll.
            $service->deployApp($site->container_backend_id, force: false);

            return;
        }

        [, $repository, $tag] = $service->parseImageRef($image);
        $spec['services'][0]['image']['repository'] = $repository;
        $spec['services'][0]['image']['tag'] = $tag;
        $service->updateApp($site->container_backend_id, $spec);
    }

    public function updateEnvVars(Site $site, ProviderCredential $credential): void
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return;
        }

        $service = new DigitalOceanAppPlatformService($credential);
        $current = $service->getApp($site->container_backend_id);
        $spec = $current['spec'] ?? [];
        if (! is_array($spec) || empty($spec['services'][0])) {
            // Spec shape unexpected — fall back to a plain redeploy.
            $service->deployApp($site->container_backend_id, force: false);

            return;
        }

        $envSpec = [];
        foreach ($this->siteEnvVars($site) as $k => $v) {
            $envSpec[] = ['key' => $k, 'value' => $v, 'scope' => 'RUN_TIME'];
        }
        foreach ($this->siteBuildEnvVars($site) as $k => $v) {
            $envSpec[] = ['key' => $k, 'value' => $v, 'scope' => 'BUILD_TIME'];
        }
        $spec['services'][0]['envs'] = $envSpec;
        // Re-push instance_count too — operators may have called
        // dply:edge:scale; pushing only envs would leave the spec
        // out of sync with what the Site says it should run.
        $spec['services'][0]['instance_count'] = $this->siteInstanceCount($site);
        $service->updateApp($site->container_backend_id, $spec);
    }

    public function teardown(Site $site, ProviderCredential $credential): void
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return;
        }

        try {
            (new DigitalOceanAppPlatformService($credential))->deleteApp($site->container_backend_id);
        } catch (\Throwable) {
            // Idempotent — already deleted is fine.
        }
    }

    public function inspect(Site $site, ProviderCredential $credential): array
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return ['phase' => 'unknown', 'live_url' => null, 'raw' => []];
        }

        $app = (new DigitalOceanAppPlatformService($credential))->getApp($site->container_backend_id);

        return [
            'phase' => (string) ($app['phase'] ?? 'unknown'),
            'live_url' => is_string($app['default_ingress'] ?? null) ? $app['default_ingress'] : null,
            'raw' => $app,
        ];
    }

    public function regions(): array
    {
        return DigitalOceanAppPlatformService::getRegions();
    }

    public function attachDomain(Site $site, ProviderCredential $credential, string $hostname): array
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return [];
        }
        $service = new DigitalOceanAppPlatformService($credential);
        $current = $service->getApp($site->container_backend_id);
        $spec = is_array($current['spec'] ?? null) ? $current['spec'] : [];
        $service->attachDomain($site->container_backend_id, $spec, $hostname);

        return [];
    }

    public function detachDomain(Site $site, ProviderCredential $credential, string $hostname): void
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return;
        }
        $service = new DigitalOceanAppPlatformService($credential);
        $current = $service->getApp($site->container_backend_id);
        $spec = is_array($current['spec'] ?? null) ? $current['spec'] : [];
        $service->detachDomain($site->container_backend_id, $spec, $hostname);
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
     * under meta.container.build_env_file_content (same .env format).
     * They map to DO scope=BUILD_TIME / App Runner BuildEnvironmentVariables —
     * needed for app secrets (e.g. private package tokens) that the
     * build step requires but shouldn't leak into runtime.
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
     * Desired instance count for the site. Operators set this via
     * dply:edge:scale; defaults to 1 when not configured.
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

    private function backendAppName(Site $site): string
    {
        // DO App Platform names: lowercase, alnum + hyphen, ≤ 32 chars.
        $name = preg_replace('/[^a-z0-9-]/i', '-', strtolower($site->slug ?: $site->name ?: 'dply-app'));
        $name = trim((string) $name, '-');

        return substr($name, 0, 32) ?: 'dply-app';
    }
}
