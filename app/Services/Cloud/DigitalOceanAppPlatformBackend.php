<?php

declare(strict_types=1);

namespace App\Services\Cloud;

use App\Models\CloudDeployTask;
use App\Models\CloudWorker;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Services\DigitalOceanAppPlatformService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DigitalOceanAppPlatformBackend implements CloudBackend
{
    use ResolvesMetricWindows;

    public function providerKey(): string
    {
        return 'digitalocean_app_platform';
    }

    public function supportsWorkers(): bool
    {
        // App Platform supports `workers` components — long-running,
        // no HTTP — in the same app spec as the web service.
        return true;
    }

    public function supportsDeployTasks(): bool
    {
        // App Platform supports `jobs` components keyed by PRE_DEPLOY /
        // POST_DEPLOY / FAILED_DEPLOY / MANUAL in the same app spec.
        return true;
    }

    public function supportsAlerts(): bool
    {
        // App Platform has first-class `alerts` in the spec plus a
        // per-alert destinations endpoint (Slack webhook + emails).
        return true;
    }

    public function cancelInProgressDeployment(Site $site, ProviderCredential $credential): bool
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return false;
        }

        $service = new DigitalOceanAppPlatformService($credential);
        $app = $service->getApp($site->container_backend_id);

        $inProgress = $app['in_progress_deployment'] ?? null;
        if (! is_array($inProgress) || ! is_string($inProgress['id'] ?? null) || $inProgress['id'] === '') {
            return false;
        }

        $service->cancelDeployment($site->container_backend_id, $inProgress['id']);

        return true;
    }

    /**
     * Resolve `username:token` for the registry credential attached to
     * the site (if any), so DO can pull private images at deploy time.
     *
     * Only GHCR currently flows through here. DOCR uses the app's DO
     * PAT transparently (no separate creds needed) and Docker Hub
     * private repos can be added the same way as GHCR when we ship
     * that credential type.
     */
    public static function imageRegistryCredentialsFor(Site $site): ?string
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $credId = $meta['container']['image_credential_id'] ?? null;
        if (! is_string($credId) || $credId === '') {
            return null;
        }

        $cred = ProviderCredential::query()->find($credId);
        if ($cred === null || $cred->organization_id !== $site->organization_id) {
            return null;
        }

        $body = is_array($cred->credentials) ? $cred->credentials : [];
        $username = (string) ($body['username'] ?? '');
        $token = (string) ($body['token'] ?? $body['api_token'] ?? '');
        if ($username === '' || $token === '') {
            return null;
        }

        return $username.':'.$token;
    }

    /**
     * Build a minimal spec body from a payload (NOT a saved Site) for
     * pre-submit validation via /apps/propose. Mirrors the shape
     * createApp/createAppFromSource emit; intentionally skips workers/
     * jobs/alerts since those don't affect propose's cost-or-error
     * outcome materially and would require persisted child rows.
     *
     * The form calls this with its own state to get a cost estimate
     * and to catch spec validation errors before the user submits.
     *
     * @param  array{name: string, region: string, size_tier_slug: string, instances: int, port: int, mode: string, image?: string, repo?: string, branch?: string, dockerfile_path?: ?string, autoscaling?: ?array, health_check?: ?array}  $payload
     * @return array<string, mixed>
     */
    public static function buildProposeSpecFromPayload(array $payload): array
    {
        $service = [
            'name' => 'web',
            'http_port' => (int) $payload['port'],
            'instance_size_slug' => (string) $payload['size_tier_slug'],
        ];

        if (($payload['mode'] ?? 'image') === 'source') {
            $service['github'] = [
                'repo' => (string) ($payload['repo'] ?? ''),
                'branch' => (string) ($payload['branch'] ?? 'main'),
                'deploy_on_push' => true,
            ];
            $dockerfile = $payload['dockerfile_path'] ?? null;
            if (is_string($dockerfile) && $dockerfile !== '') {
                $service['dockerfile_path'] = $dockerfile;
            }
        } else {
            $service['image'] = DigitalOceanAppPlatformService::imageSpecBlock((string) ($payload['image'] ?? ''));
        }

        $autoscaling = $payload['autoscaling'] ?? null;
        if (is_array($autoscaling) && ($autoscaling['enabled'] ?? false)) {
            $service['autoscaling'] = [
                'min_instance_count' => (int) ($autoscaling['min_instances'] ?? 1),
                'max_instance_count' => (int) ($autoscaling['max_instances'] ?? 3),
                'metrics' => [
                    'cpu' => ['percent' => (int) ($autoscaling['cpu_percent'] ?? 75)],
                ],
            ];
        } else {
            $service['instance_count'] = max(1, (int) $payload['instances']);
        }

        $health = $payload['health_check'] ?? null;
        if (is_array($health) && ($health['enabled'] ?? false)) {
            $service['health_check'] = [
                'http_path' => (string) ($health['http_path'] ?? '/'),
                'period_seconds' => (int) ($health['period_seconds'] ?? 30),
                'timeout_seconds' => (int) ($health['timeout_seconds'] ?? 5),
                'failure_threshold' => (int) ($health['failure_threshold'] ?? 3),
            ];
        }

        return [
            'name' => (string) $payload['name'] ?: 'dply-propose-probe',
            'region' => (string) $payload['region'] ?: 'nyc',
            'services' => [$service],
        ];
    }

    public function supportsAutoscaling(): bool
    {
        // App Platform supports both an `autoscaling` block and a
        // service `health_check` block first-class in the app spec.
        return true;
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
        $alerts = is_array($result['alerts'] ?? null) ? $result['alerts'] : [];
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

    /**
     * Rebuild the app spec's `workers` array from the site's current
     * CloudWorker rows and PUT it, then roll a deployment so the new
     * set of background components takes effect.
     *
     * The spec is fetched fresh and only its `workers` key is replaced
     * — rebuilding from the CloudWorker rows each call means a deleted
     * worker is simply omitted. No-op when the site has not been
     * provisioned on the backend yet.
     */
    public function syncWorkers(Site $site, ProviderCredential $credential): void
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return;
        }

        $service = new DigitalOceanAppPlatformService($credential);
        $current = $service->getApp($site->container_backend_id);
        $spec = $current['spec'] ?? [];
        if (! is_array($spec) || empty($spec['services'][0])) {
            // Spec shape unexpected — fall back to a plain redeploy so
            // the operator at least sees a roll.
            $service->deployApp($site->container_backend_id, force: false);

            return;
        }

        // Re-emit the autoscaling / health-check blocks alongside the
        // workers so a worker sync never drops the scaling config.
        $spec = $this->applyScalingToSpec($spec, $site);
        $spec = $this->applyWorkersToSpec($spec, $site);
        $service->updateApp($site->container_backend_id, $spec);
        $service->deployApp($site->container_backend_id, force: false);
    }

    /**
     * Roll a deployment that re-pushes the site's autoscaling +
     * health-check config (and, as a side effect, the worker spec)
     * into the live app spec. Used by ConfigureCloudAutoscaling /
     * ConfigureCloudHealthCheck via SyncCloudScalingJob.
     *
     * No-op when the site has not been provisioned on the backend yet.
     */
    public function syncScaling(Site $site, ProviderCredential $credential): void
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

        $spec = $this->applyScalingToSpec($spec, $site);
        $spec = $this->applyWorkersToSpec($spec, $site);
        $service->updateApp($site->container_backend_id, $spec);
        $service->deployApp($site->container_backend_id, force: false);
    }

    /**
     * Weave the site's autoscaling + health-check config into the web
     * service of an existing app spec.
     *
     * Autoscaling and a fixed `instance_count` are mutually exclusive
     * on App Platform: when autoscaling is enabled this sets the
     * `autoscaling` block and unsets `instance_count`; when disabled
     * it removes any `autoscaling` block and restores the fixed
     * `instance_count` from the site's meta. Health-check is set or
     * removed independently.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function applyScalingToSpec(array $spec, Site $site): array
    {
        if (! is_array($spec['services'][0] ?? null)) {
            return $spec;
        }

        $autoscaling = CloudScalingConfig::doAutoscalingBlock($site);
        if ($autoscaling !== null) {
            $spec['services'][0]['autoscaling'] = $autoscaling;
            unset($spec['services'][0]['instance_count']);
        } else {
            unset($spec['services'][0]['autoscaling']);
            $spec['services'][0]['instance_count'] = $this->siteInstanceCount($site);
        }

        $healthCheck = CloudScalingConfig::doHealthCheckBlock($site);
        if ($healthCheck !== null) {
            $spec['services'][0]['health_check'] = $healthCheck;
        } else {
            unset($spec['services'][0]['health_check']);
        }

        return $spec;
    }

    /**
     * Replace the `workers` key on an existing app spec with components
     * rebuilt from the site's CloudWorker rows. Each worker reuses the
     * web service's source — git `github` block when source-mode, the
     * `image` block when image-mode — so workers always run the same
     * code as the web process. When there are no workers the key is
     * removed entirely (DO rejects an empty workers array).
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function applyWorkersToSpec(array $spec, Site $site): array
    {
        $webService = is_array($spec['services'][0] ?? null) ? $spec['services'][0] : [];

        // Pull the source block off the live web service so workers
        // share it exactly (git source mode vs Docker image mode).
        $sourceBlock = [];
        if (is_array($webService['github'] ?? null)) {
            $sourceBlock['github'] = $webService['github'];
        } elseif (is_array($webService['image'] ?? null)) {
            $sourceBlock['image'] = $webService['image'];
        }
        if (is_string($webService['dockerfile_path'] ?? null) && $webService['dockerfile_path'] !== '') {
            $sourceBlock['dockerfile_path'] = $webService['dockerfile_path'];
        }

        $env = $this->siteEnvVars($site);
        $buildEnv = $this->siteBuildEnvVars($site);

        $components = $this->buildWorkerComponents($site, $env, $buildEnv, $sourceBlock);

        if ($components === []) {
            unset($spec['workers']);
        } else {
            $spec['workers'] = $components;
        }

        return $spec;
    }

    /**
     * Build the `workers` components array for a fresh provision —
     * one component per CloudWorker row. The source block is derived
     * from $sourceSpec (source mode) when present, otherwise from the
     * site's container_image (image mode).
     *
     * @param  array<string, string>  $env
     * @param  array<string, string>  $buildEnv
     * @param  array{repo: string, branch: string, dockerfile_path: ?string, deploy_on_push: bool}|null  $sourceSpec
     * @return list<array<string, mixed>>
     */
    private function workerComponentsFor(Site $site, array $env, array $buildEnv, ?array $sourceSpec = null): array
    {
        if ($sourceSpec !== null) {
            $sourceBlock = ['github' => [
                'repo' => $sourceSpec['repo'],
                'branch' => $sourceSpec['branch'],
                'deploy_on_push' => $sourceSpec['deploy_on_push'],
            ]];
            if (is_string($sourceSpec['dockerfile_path']) && $sourceSpec['dockerfile_path'] !== '') {
                $sourceBlock['dockerfile_path'] = $sourceSpec['dockerfile_path'];
            }
        } else {
            $sourceBlock = ['image' => DigitalOceanAppPlatformService::imageSpecBlock(
                (string) ($site->container_image ?? ''),
                self::imageRegistryCredentialsFor($site),
            )];
        }

        return $this->buildWorkerComponents($site, $env, $buildEnv, $sourceBlock);
    }

    /**
     * Turn the site's CloudWorker rows into DO `workers` components.
     * Each component carries the shared source block, the worker's
     * effective run command, instance count and size slug, and the
     * site's runtime/build env vars (workers need the same config as
     * the web process — DB creds, queue connection, app key, etc.).
     *
     * @param  array<string, mixed>  $sourceBlock
     * @param  array<string, string>  $env
     * @param  array<string, string>  $buildEnv
     * @return list<array<string, mixed>>
     */
    private function buildWorkerComponents(Site $site, array $env, array $buildEnv, array $sourceBlock): array
    {
        $workers = CloudWorker::query()
            ->where('site_id', $site->id)
            ->orderBy('created_at')
            ->get();

        if ($workers->isEmpty()) {
            return [];
        }

        $envSpec = [];
        foreach ($env as $k => $v) {
            $envSpec[] = ['key' => $k, 'value' => $v, 'scope' => 'RUN_TIME'];
        }
        foreach ($buildEnv as $k => $v) {
            $envSpec[] = ['key' => $k, 'value' => $v, 'scope' => 'BUILD_TIME'];
        }

        $components = [];
        $used = [];
        foreach ($workers as $worker) {
            // DO component names must be unique within the app and
            // match [a-z0-9-]; derive a stable, unique name per row.
            $name = $this->workerComponentName($worker, $used);
            $used[$name] = true;

            $components[] = array_merge($sourceBlock, [
                'name' => $name,
                'run_command' => $worker->effectiveCommand(),
                'instance_count' => $worker->effectiveInstanceCount(),
                'instance_size_slug' => $worker->backendSizeSlug(),
                'envs' => $envSpec,
            ]);
        }

        return $components;
    }

    /**
     * A DO-safe, app-unique component name for a worker row.
     *
     * @param  array<string, bool>  $used
     */
    private function workerComponentName(CloudWorker $worker, array $used): string
    {
        $base = $worker->isScheduler() ? 'scheduler' : 'worker';
        $slug = strtolower((string) preg_replace('/[^a-z0-9-]/i', '-', (string) $worker->name));
        $slug = trim($slug, '-');
        $name = $slug !== '' ? $base.'-'.$slug : $base;
        $name = substr($name, 0, 28);

        if (! isset($used[$name])) {
            return $name;
        }

        // Collision — append a short suffix from the ulid.
        $suffix = strtolower(substr((string) $worker->id, -5));

        return substr($name, 0, 22).'-'.$suffix;
    }

    /**
     * Build the DO `jobs` components array for a site. Mirrors the
     * worker pair but emits `kind` (PRE_DEPLOY / POST_DEPLOY /
     * FAILED_DEPLOY / MANUAL) instead of a long-running instance count.
     *
     * @param  array<string, string>  $env
     * @param  array<string, string>  $buildEnv
     * @param  array{repo: string, branch: string, dockerfile_path: ?string, deploy_on_push: bool}|null  $sourceSpec
     * @return list<array<string, mixed>>
     */
    private function jobComponentsFor(Site $site, array $env, array $buildEnv, ?array $sourceSpec = null): array
    {
        if ($sourceSpec !== null) {
            $sourceBlock = ['github' => [
                'repo' => $sourceSpec['repo'],
                'branch' => $sourceSpec['branch'],
                'deploy_on_push' => $sourceSpec['deploy_on_push'],
            ]];
            if (is_string($sourceSpec['dockerfile_path']) && $sourceSpec['dockerfile_path'] !== '') {
                $sourceBlock['dockerfile_path'] = $sourceSpec['dockerfile_path'];
            }
        } else {
            $sourceBlock = ['image' => DigitalOceanAppPlatformService::imageSpecBlock(
                (string) ($site->container_image ?? ''),
                self::imageRegistryCredentialsFor($site),
            )];
        }

        $tasks = CloudDeployTask::query()
            ->where('site_id', $site->id)
            ->orderBy('created_at')
            ->get();

        if ($tasks->isEmpty()) {
            return [];
        }

        $envSpec = [];
        foreach ($env as $k => $v) {
            $envSpec[] = ['key' => $k, 'value' => $v, 'scope' => 'RUN_TIME'];
        }
        foreach ($buildEnv as $k => $v) {
            $envSpec[] = ['key' => $k, 'value' => $v, 'scope' => 'BUILD_TIME'];
        }

        $components = [];
        $used = [];
        foreach ($tasks as $task) {
            $name = $this->jobComponentName($task, $used);
            $used[$name] = true;

            $components[] = array_merge($sourceBlock, [
                'name' => $name,
                'kind' => $task->doKind(),
                'run_command' => $task->command,
                'instance_count' => 1,
                'instance_size_slug' => $task->backendSizeSlug(),
                'envs' => $envSpec,
            ]);
        }

        return $components;
    }

    /**
     * A DO-safe, app-unique component name for a job row.
     *
     * @param  array<string, bool>  $used
     */
    private function jobComponentName(CloudDeployTask $task, array $used): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9-]/i', '-', (string) $task->name));
        $slug = trim($slug, '-');
        $name = $slug !== '' ? 'job-'.$slug : 'job';
        $name = substr($name, 0, 28);

        if (! isset($used[$name])) {
            return $name;
        }

        $suffix = strtolower(substr((string) $task->id, -5));

        return substr($name, 0, 22).'-'.$suffix;
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

    public function recentDeployments(Site $site, ProviderCredential $credential, int $limit = 10): array
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return [];
        }

        $service = new DigitalOceanAppPlatformService($credential);
        $raw = $service->listDeployments($site->container_backend_id, $limit);

        return array_map(static function (array $entry): array {
            $cause = is_string($entry['cause_details']['type'] ?? null) ? (string) $entry['cause_details']['type'] : null;

            return [
                'id' => (string) ($entry['id'] ?? ''),
                'phase' => (string) ($entry['phase'] ?? 'UNKNOWN'),
                'started_at' => is_string($entry['created_at'] ?? null) ? (string) $entry['created_at'] : null,
                'finished_at' => is_string($entry['updated_at'] ?? null) ? (string) $entry['updated_at'] : null,
                'cause' => $cause,
            ];
        }, $raw);
    }

    public function latestDeploymentLogs(Site $site, ProviderCredential $credential): array
    {
        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return ['content' => null, 'url' => null, 'message' => 'Site has not been provisioned on the backend yet.'];
        }

        $service = new DigitalOceanAppPlatformService($credential);
        $result = $service->getLatestDeploymentLogs($site->container_backend_id);

        if ($result['url'] === null) {
            return ['content' => null, 'url' => null, 'message' => 'No deployment logs available yet — DO has not produced a log link.'];
        }

        return ['content' => null, 'url' => $result['url'], 'message' => null];
    }

    /**
     * Live-fetch CPU / memory / restart metrics from DO's App Platform
     * monitoring API, normalized to the CloudBackend metrics shape.
     *
     * Wrapped in a 60s cache keyed by site + window so repeated
     * dashboard renders don't hammer the monitoring API. Any failure
     * (unprovisioned site, API error, unexpected shape) degrades to
     * available:false rather than throwing.
     */
    public function metrics(Site $site, ProviderCredential $credential, string $window): array
    {
        $window = $this->normalizeWindow($window);

        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return [
                'window' => $window,
                'series' => ['cpu' => [], 'memory' => [], 'restarts' => []],
                'available' => false,
                'note' => 'Site has not been provisioned on the backend yet.',
            ];
        }

        return Cache::remember(
            self::metricsCacheKey($site, $window),
            self::CACHE_TTL_SECONDS,
            function () use ($site, $credential, $window): array {
                [$start, $end] = $this->windowBounds($window);
                $appId = (string) $site->container_backend_id;

                try {
                    $service = new DigitalOceanAppPlatformService($credential);

                    return [
                        'window' => $window,
                        'series' => [
                            'cpu' => $service->getAppMetric($appId, 'cpu_percentage', $start, $end),
                            'memory' => $service->getAppMetric($appId, 'memory_percentage', $start, $end),
                            'restarts' => $service->getAppMetric($appId, 'restart_count', $start, $end),
                        ],
                        'available' => true,
                    ];
                } catch (\Throwable $e) {
                    return [
                        'window' => $window,
                        'series' => ['cpu' => [], 'memory' => [], 'restarts' => []],
                        'available' => false,
                        'note' => 'Could not fetch metrics from DigitalOcean: '.$e->getMessage(),
                    ];
                }
            },
        );
    }

    /**
     * Live-fetch RUN (runtime) logs for the app's web component.
     *
     * DO returns a presigned archive URL; we download it and split it
     * into lines, capped at $lines. Both the URL resolution and the
     * archive download are cached for 60s. Failures degrade to
     * available:false.
     */
    public function runtimeLogs(Site $site, ProviderCredential $credential, int $lines = 200, string $component = 'web'): array
    {
        $lines = max(1, min(2000, $lines));

        // Lock the component value to DO-safe characters; anything else
        // falls back to "web" so a bad query string can't be used to
        // probe arbitrary paths on DO's API.
        $component = preg_match('/^[a-z0-9-]+$/', $component) === 1
            ? substr($component, 0, 60)
            : 'web';

        if (! is_string($site->container_backend_id) || $site->container_backend_id === '') {
            return [
                'lines' => [],
                'available' => false,
                'note' => 'Site has not been provisioned on the backend yet.',
            ];
        }

        return Cache::remember(
            self::runtimeLogsCacheKey($site, $lines).':'.$component,
            self::CACHE_TTL_SECONDS,
            function () use ($site, $credential, $lines, $component): array {
                $appId = (string) $site->container_backend_id;

                try {
                    $service = new DigitalOceanAppPlatformService($credential);
                    $result = $service->getRuntimeLogs($appId, $component);
                } catch (\Throwable $e) {
                    return [
                        'lines' => [],
                        'available' => false,
                        'note' => 'Could not fetch runtime logs from DigitalOcean: '.$e->getMessage(),
                    ];
                }

                $archiveUrl = $result['url'];
                if (! is_string($archiveUrl) || $archiveUrl === '') {
                    return [
                        'lines' => [],
                        'available' => true,
                        'url' => is_string($result['live_url'] ?? null) ? $result['live_url'] : null,
                        'note' => 'No archived runtime logs yet — the app may not have produced output, or DO has not flushed an archive.',
                    ];
                }

                // The historic_urls archive is a presigned URL with no
                // auth — fetch it directly and tail to $lines.
                try {
                    $response = Http::timeout(8)->get($archiveUrl);
                    if (! $response->successful()) {
                        return [
                            'lines' => [],
                            'available' => true,
                            'url' => $archiveUrl,
                            'note' => 'Runtime log archive is available but could not be downloaded inline.',
                        ];
                    }
                    $body = trim($response->body());
                } catch (\Throwable) {
                    return [
                        'lines' => [],
                        'available' => true,
                        'url' => $archiveUrl,
                        'note' => 'Runtime log archive is available but could not be downloaded inline.',
                    ];
                }

                $allLines = $body === '' ? [] : explode("\n", $body);
                $tail = array_slice($allLines, -$lines);

                return [
                    'lines' => array_values($tail),
                    'available' => true,
                    'url' => $archiveUrl,
                ];
            },
        );
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
     * dply:cloud:scale; defaults to 1 when not configured.
     */
    private function siteInstanceCount(Site $site): int
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $raw = $meta['container']['instance_count'] ?? null;

        return is_int($raw) && $raw > 0 ? $raw : 1;
    }

    /**
     * Map the site's portable size_tier to DO App Platform's
     * instance_size_slug. Basic tiers map to `basic-*` slugs (the
     * default, cheapest path). The Pro variants (`*-pro`) map to
     * `apps-d-*` Professional slugs and are required when CPU
     * autoscaling is enabled — Basic tier rejects autoscaling at
     * spec-validation time on DO's side. Operators opt into Pro
     * deliberately via the size selector or `dply:cloud:resize`;
     * we never auto-upgrade behind their back because the cost
     * delta is significant.
     */
    private function siteSizeSlugForDo(Site $site): string
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $tier = (string) ($meta['container']['size_tier'] ?? 'small');

        return match ($tier) {
            'medium' => 'basic-xs',
            'large' => 'basic-s',
            'xlarge' => 'basic-m',
            'small-pro' => 'apps-d-1vcpu-0.5gb',
            'medium-pro' => 'apps-d-1vcpu-1gb',
            'large-pro' => 'apps-d-1vcpu-2gb',
            'xlarge-pro' => 'apps-d-2vcpu-4gb',
            default => 'basic-xxs',
        };
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

    /**
     * Split a Docker image ref into [registry_host, repository, tag] —
     * the same parsing DigitalOceanAppPlatformService uses, duplicated
     * here so worker spec building does not need a credential-bound
     * service instance just to parse a string.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function parseImageRef(string $image): array
    {
        $tag = 'latest';
        $lastColon = strrpos($image, ':');
        $lastSlash = strrpos($image, '/');
        if ($lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash)) {
            $tag = substr($image, $lastColon + 1);
            $image = substr($image, 0, $lastColon);
        }

        $parts = explode('/', $image);
        $registry = 'docker.io';
        if (count($parts) > 1 && (str_contains($parts[0], '.') || str_contains($parts[0], ':'))) {
            $registry = array_shift($parts);
        }
        $repository = implode('/', $parts);
        if ($registry === 'docker.io' && ! str_contains($repository, '/')) {
            $repository = 'library/'.$repository;
        }

        return [$registry, $repository, $tag];
    }

    private function backendAppName(Site $site): string
    {
        // DO App Platform names: lowercase, alnum + hyphen, ≤ 32 chars.
        $name = preg_replace('/[^a-z0-9-]/i', '-', strtolower($site->slug ?: $site->name ?: 'dply-app'));
        $name = trim((string) $name, '-');

        return substr($name, 0, 32) ?: 'dply-app';
    }
}
