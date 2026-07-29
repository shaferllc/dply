<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Backends\Concerns;

use App\Models\CloudDeployTask;
use App\Models\CloudWorker;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Modules\Cloud\Backends\CloudScalingConfig;
use App\Modules\Cloud\Services\DigitalOceanAppPlatformService;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDoAppWorkersScaling
{


    public function supportsAutoscaling(): bool
    {
        // App Platform supports both an `autoscaling` block and a
        // service `health_check` block first-class in the app spec.
        return true;
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
     * @param  array<string, mixed> $spec
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
     * @param  array<string, mixed> $spec
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
     * @param  array<string, mixed> $env
     * @param  array<string, mixed> $buildEnv
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
     * @param  array<string, mixed> $sourceBlock
     * @param  array<string, mixed> $env
     * @param  array<string, mixed> $buildEnv
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
     * @param  array<string, mixed> $used
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
     * @param  array<string, mixed> $env
     * @param  array<string, mixed> $buildEnv
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
     * @param  array<string, mixed> $used
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
}
