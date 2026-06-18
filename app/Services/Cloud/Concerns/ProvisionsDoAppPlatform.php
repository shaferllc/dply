<?php

declare(strict_types=1);

namespace App\Services\Cloud\Concerns;

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Services\Cloud\CloudAlerts;
use App\Services\Cloud\CloudScalingConfig;
use App\Services\DigitalOceanAppPlatformService;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ProvisionsDoAppPlatform
{


    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
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
            instanceSizeSlug: $this->siteSizeSlugForDo($site),
            workers: $this->workerComponentsFor($site, $env, $buildEnv),
            autoscaling: CloudScalingConfig::doAutoscalingBlock($site),
            healthCheck: CloudScalingConfig::doHealthCheckBlock($site),
            jobs: $this->jobComponentsFor($site, $env, $buildEnv),
            alerts: CloudAlerts::doAlertsBlock($site),
            registryCredentials: self::imageRegistryCredentialsFor($site),
        );

        $this->applyAlertDestinations($service, $result, $site);

        return [
            'backend_id' => $result['id'],
            'live_url' => $result['default_ingress'],
        ];
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function provisionFromSource(Site $site, ProviderCredential $credential): array
    {
        $service = new DigitalOceanAppPlatformService($credential);
        $source = $this->sourceSpec($site);

        $env = $this->siteEnvVars($site);
        $buildEnv = $this->siteBuildEnvVars($site);
        $result = $service->createAppFromSource(
            appName: $this->backendAppName($site),
            region: $site->container_region ?: 'nyc',
            repo: $source['repo'],
            branch: $source['branch'],
            port: (int) ($site->container_port ?: 8080),
            deployOnPush: $source['deploy_on_push'],
            dockerfilePath: $source['dockerfile_path'],
            envVars: $env,
            buildEnvVars: $buildEnv,
            instanceCount: $this->siteInstanceCount($site),
            instanceSizeSlug: $this->siteSizeSlugForDo($site),
            workers: $this->workerComponentsFor($site, $env, $buildEnv, $source),
            autoscaling: CloudScalingConfig::doAutoscalingBlock($site),
            healthCheck: CloudScalingConfig::doHealthCheckBlock($site),
            jobs: $this->jobComponentsFor($site, $env, $buildEnv, $source),
            alerts: CloudAlerts::doAlertsBlock($site),
        );

        $this->applyAlertDestinations($service, $result, $site);

        return [
            'backend_id' => $result['id'],
            'live_url' => $result['default_ingress'],
        ];
    }

    /**
     * After createApp returns, DO has assigned IDs to each alert in the
     * spec. PUT destinations for each one (Slack + emails) so the
     * notifications actually land. Failures don't abort provision —
     * the app is up; alerts can be retried via syncAlerts later.
     *
     * @param  array{id: string, default_ingress: ?string, alerts: list<array<string, mixed>>}  $result
     */
    private function applyAlertDestinations(DigitalOceanAppPlatformService $service, array $result, Site $site): void
    {
        $alerts = ($result['alerts'] );
        if ($alerts === []) {
            return;
        }

        $organization = $site->organization;
        if ($organization === null) {
            return;
        }

        $destinations = CloudAlerts::destinationsFor($site, $organization);
        if ($destinations['slack_webhooks'] === [] && $destinations['emails'] === []) {
            return;
        }

        foreach ($alerts as $alert) {
            $alertId = (string) ($alert['id'] ?? '');
            if ($alertId === '') {
                continue;
            }
            try {
                $service->updateAlertDestinations((string) $result['id'], $alertId, $destinations);
            } catch (\Throwable $e) {
                // Soft-fail — the app is provisioned; one alert without
                // wired destinations is recoverable. The error lands in
                // the dply log; future syncAlerts() can retry.
                report($e);
            }
        }
    }

    /**
     * @return array{repo: string, branch: string, dockerfile_path: ?string, deploy_on_push: bool}
     */
    private function sourceSpec(Site $site): array
    {
        $meta = ($site->meta );
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

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
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

        $spec['services'][0]['image'] = DigitalOceanAppPlatformService::imageSpecBlock(
            $image,
            self::imageRegistryCredentialsFor($site),
        );
        // Re-emit the autoscaling / health-check blocks and the workers
        // array so an image bump never silently drops them.
        $spec = $this->applyScalingToSpec($spec, $site);
        $spec = $this->applyWorkersToSpec($spec, $site);
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
        // Re-push size too — operators may have called dply:cloud:resize;
        // pushing only envs would leave the spec out of sync.
        $spec['services'][0]['instance_size_slug'] = $this->siteSizeSlugForDo($site);
        // Re-emit the autoscaling / health-check blocks (this also
        // restores the fixed instance_count when autoscaling is off)
        // and the workers array so an env-vars push never silently
        // drops or stales any of them.
        $spec = $this->applyScalingToSpec($spec, $site);
        $spec = $this->applyWorkersToSpec($spec, $site);
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
}
