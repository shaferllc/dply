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
        $result = $service->createApp(
            appName: $this->backendAppName($site),
            region: $site->container_region ?: 'nyc',
            image: (string) $site->container_image,
            port: (int) ($site->container_port ?: 8080),
            envVars: $env,
        );

        return [
            'backend_id' => $result['id'],
            'live_url' => $result['default_ingress'],
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

    private function backendAppName(Site $site): string
    {
        // DO App Platform names: lowercase, alnum + hyphen, ≤ 32 chars.
        $name = preg_replace('/[^a-z0-9-]/i', '-', strtolower($site->slug ?: $site->name ?: 'dply-app'));
        $name = trim((string) $name, '-');

        return substr($name, 0, 32) ?: 'dply-app';
    }
}
