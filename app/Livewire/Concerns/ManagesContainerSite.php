<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Cloud\ConfigureCloudAutoscaling;
use App\Actions\Cloud\ConfigureCloudHealthCheck;
use App\Actions\Cloud\CreateCloudWorker;
use App\Jobs\AttachCloudDatabaseJob;
use App\Jobs\AttachCloudDomainJob;
use App\Jobs\DetachCloudDomainJob;
use App\Jobs\RedeployCloudSiteJob;
use App\Jobs\SyncCloudWorkersJob;
use App\Jobs\TeardownCloudSiteJob;
use App\Models\CloudDatabase;
use App\Models\CloudWorker;
use App\Models\Site;
use App\Services\Cloud\CloudRouter;
use App\Services\Cloud\CloudScalingConfig;
use App\Services\Cloud\ResolvesMetricWindows;

/**
 * Methods bolted onto Sites\Settings (and any future container
 * dashboard surfaces) for triggering cloud actions on a container
 * site. Lives in its own trait so the giant Settings.php class
 * stays focused on its existing PHP/Laravel/Node responsibilities.
 *
 * Assumes a public $site property of type Site on the host class.
 */
trait ManagesContainerSite
{
    public string $container_image_input = '';

    public string $container_domain_input = '';

    public string $container_env_file_input = '';

    public string $container_build_env_file_input = '';

    /** Selected managed database id in the "attach a database" picker. */
    public string $container_database_attach_id = '';

    /** Add-worker form inputs (the dashboard Workers section). */
    public string $container_worker_command_input = '';

    public string $container_worker_size_input = 'small';

    public int $container_worker_count_input = 1;

    /* Scaling & health — autoscaling form inputs. */
    public bool $container_autoscaling_enabled = false;

    public int $container_autoscaling_min = CloudScalingConfig::DEFAULT_MIN_INSTANCES;

    public int $container_autoscaling_max = CloudScalingConfig::DEFAULT_MAX_INSTANCES;

    public int $container_autoscaling_cpu = CloudScalingConfig::DEFAULT_CPU_PERCENT;

    /* Scaling & health — health-check form inputs. */
    public bool $container_health_check_enabled = false;

    public string $container_health_check_path = CloudScalingConfig::DEFAULT_HEALTH_PATH;

    public int $container_health_check_initial_delay = CloudScalingConfig::DEFAULT_INITIAL_DELAY_SECONDS;

    public int $container_health_check_period = CloudScalingConfig::DEFAULT_PERIOD_SECONDS;

    public int $container_health_check_timeout = CloudScalingConfig::DEFAULT_TIMEOUT_SECONDS;

    public int $container_health_check_success = CloudScalingConfig::DEFAULT_SUCCESS_THRESHOLD;

    public int $container_health_check_failure = CloudScalingConfig::DEFAULT_FAILURE_THRESHOLD;

    /**
     * Guards the one-time hydration of the scaling & health-check
     * form inputs. boot() runs on every Livewire request — without
     * this flag the inputs would be reset from meta on every round
     * trip, discarding the operator's unsaved edits.
     */
    public bool $container_scaling_inputs_hydrated = false;

    /**
     * Populated by fetchContainerLogs(); shape matches
     * CloudBackend::latestDeploymentLogs return: { content?, url?, message? }.
     *
     * @var array<string, ?string>|null
     */
    public ?array $container_logs_result = null;

    /**
     * Populated by fetchContainerDeployments(); list of normalized
     * deployment rows: { id, phase, started_at, finished_at, cause }.
     *
     * @var list<array<string, ?string>>|null
     */
    public ?array $container_deployments_result = null;

    /** Selected metrics window for the Observability section: 1h | 6h | 24h. */
    public string $container_metrics_window = '1h';

    /**
     * Populated by refreshContainerMetrics(); shape matches
     * CloudBackend::metrics return: { window, series, available, note?, url? }.
     *
     * @var array<string, mixed>|null
     */
    public ?array $container_metrics_result = null;

    /**
     * Populated by fetchContainerRuntimeLogs(); shape matches
     * CloudBackend::runtimeLogs return: { lines, available, url?, note? }.
     *
     * @var array<string, mixed>|null
     */
    public ?array $container_runtime_logs_result = null;

    public function bootManagesContainerSite(): void
    {
        if ($this->container_image_input === '' && isset($this->site)) {
            $this->container_image_input = (string) ($this->site->container_image ?? '');
        }
        if ($this->container_env_file_input === '' && isset($this->site)) {
            $this->container_env_file_input = (string) ($this->site->env_file_content ?? '');
        }
        if ($this->container_build_env_file_input === '' && isset($this->site)) {
            $meta = is_array($this->site->meta) ? $this->site->meta : [];
            $this->container_build_env_file_input = (string) ($meta['container']['build_env_file_content'] ?? '');
        }

        // Hydrate the scaling & health-check form inputs from the
        // site's current meta config so the dashboard reflects state.
        // One-time only — boot() runs on every request, so re-running
        // this would discard the operator's unsaved edits.
        if (isset($this->site) && ! $this->container_scaling_inputs_hydrated) {
            $this->container_scaling_inputs_hydrated = true;
            $autoscaling = CloudScalingConfig::autoscaling($this->site);
            $this->container_autoscaling_enabled = $autoscaling['enabled'];
            $this->container_autoscaling_min = $autoscaling['min_instances'];
            $this->container_autoscaling_max = $autoscaling['max_instances'];
            $this->container_autoscaling_cpu = $autoscaling['cpu_percent'];

            $healthCheck = CloudScalingConfig::healthCheck($this->site);
            $this->container_health_check_enabled = $healthCheck['enabled'];
            $this->container_health_check_path = $healthCheck['http_path'];
            $this->container_health_check_initial_delay = $healthCheck['initial_delay_seconds'];
            $this->container_health_check_period = $healthCheck['period_seconds'];
            $this->container_health_check_timeout = $healthCheck['timeout_seconds'];
            $this->container_health_check_success = $healthCheck['success_threshold'];
            $this->container_health_check_failure = $healthCheck['failure_threshold'];
        }
    }

    public function saveContainerEnvAndRedeploy(): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);
        $this->validate([
            'container_env_file_input' => 'nullable|string|max:65535',
            'container_build_env_file_input' => 'nullable|string|max:65535',
        ]);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['container'] = array_merge($meta['container'] ?? [], [
            'build_env_file_content' => $this->container_build_env_file_input,
        ]);
        $this->site->update([
            'env_file_content' => $this->container_env_file_input,
            'meta' => $meta,
        ]);

        $backend = CloudRouter::backendFor($this->site->fresh());
        $credential = CloudRouter::credentialFor($this->site->fresh());
        if ($backend !== null && $credential !== null) {
            try {
                $backend->updateEnvVars($this->site->fresh(), $credential);
            } catch (\Throwable $e) {
                if (method_exists($this, 'toastError')) {
                    $this->toastError(__('Saved env vars locally, but pushing to backend failed: :err', ['err' => $e->getMessage()]));
                }

                return;
            }
        }

        RedeployCloudSiteJob::dispatch($this->site->id);

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Env vars saved and redeploy queued. The backend will pick up the new values on the next roll.'));
        }
    }

    public function redeployContainer(): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $newImage = trim($this->container_image_input);
        $changed = $newImage !== '' && $newImage !== (string) $this->site->container_image;

        RedeployCloudSiteJob::dispatch($this->site->id, $changed ? $newImage : null);

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess($changed
                ? __('Image updated and redeploy queued.')
                : __('Redeploy queued.'));
        }
    }

    public function tearDownContainer(): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('delete', $this->site);

        TeardownCloudSiteJob::dispatch($this->site->id);

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Tear-down queued. The container will be deleted on the backend shortly.'));
        }
    }

    /**
     * Tear down a preview deployment that's a child of the current
     * source-mode parent site. Authorisation goes through the parent
     * — if you can edit the parent, you can manage its previews.
     */
    public function tearDownContainerPreview(string $previewSiteId): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $preview = Site::query()->find($previewSiteId);
        if ($preview === null
            || $preview->organization_id !== $this->site->organization_id
            || ($preview->meta['container']['preview_parent_site_id'] ?? null) !== $this->site->id) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Preview not found or not a child of this site.'));
            }

            return;
        }

        TeardownCloudSiteJob::dispatch($preview->id);

        if (method_exists($this, 'toastSuccess')) {
            $branch = (string) ($preview->meta['container']['preview_branch'] ?? '');
            $this->toastSuccess(__('Preview teardown queued for branch :branch.', ['branch' => $branch]));
        }
    }

    public function attachContainerDomain(): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $hostname = strtolower(trim($this->container_domain_input));
        $hostname = preg_replace('#^https?://#', '', (string) $hostname);
        $hostname = rtrim((string) $hostname, '/');
        if ($hostname === '' || ! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/i', $hostname)) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Hostname does not look valid.'));
            }

            return;
        }

        AttachCloudDomainJob::dispatch($this->site->id, $hostname);
        $this->container_domain_input = '';

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Domain attach queued. DNS validation records will appear here shortly.'));
        }
    }

    public function detachContainerDomain(string $hostname): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        DetachCloudDomainJob::dispatch($this->site->id, $hostname);

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Domain detach queued.'));
        }
    }

    public function fetchContainerLogs(): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('view', $this->site);

        $backend = CloudRouter::backendFor($this->site);
        $credential = CloudRouter::credentialFor($this->site);
        if ($backend === null || $credential === null) {
            $this->container_logs_result = [
                'content' => null,
                'url' => null,
                'message' => __('No backend or credential resolvable for this site.'),
            ];

            return;
        }

        try {
            $this->container_logs_result = $backend->latestDeploymentLogs($this->site, $credential);
        } catch (\Throwable $e) {
            $this->container_logs_result = [
                'content' => null,
                'url' => null,
                'message' => __('Failed to fetch logs: :err', ['err' => $e->getMessage()]),
            ];
        }
    }

    public function fetchContainerDeployments(): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('view', $this->site);

        $backend = CloudRouter::backendFor($this->site);
        $credential = CloudRouter::credentialFor($this->site);
        if ($backend === null || $credential === null) {
            $this->container_deployments_result = [];

            return;
        }

        try {
            $this->container_deployments_result = $backend->recentDeployments($this->site, $credential, 10);
        } catch (\Throwable $e) {
            $this->container_deployments_result = [];
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Failed to fetch deployments: :err', ['err' => $e->getMessage()]));
            }
        }
    }

    /* ========================================================================
     * Observability — metrics & runtime logs
     * ======================================================================== */

    /**
     * Switch the metrics window (1h / 6h / 24h) and re-fetch. Invalid
     * codes are ignored — the picker only ever sends valid values.
     */
    public function setContainerMetricsWindow(string $window): void
    {
        if (! in_array($window, ResolvesMetricWindows::metricWindows(), true)) {
            return;
        }
        $this->container_metrics_window = $window;
        $this->refreshContainerMetrics();
    }

    /**
     * Fetch CPU / memory / restart metrics for the current window
     * via the site's cloud backend. Backend results are 60s-cached;
     * this just surfaces them. Never throws — degrades to an
     * available:false result on error.
     */
    public function refreshContainerMetrics(): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('view', $this->site);

        $window = $this->container_metrics_window;

        $backend = CloudRouter::backendFor($this->site);
        $credential = CloudRouter::credentialFor($this->site);
        if ($backend === null || $credential === null) {
            $this->container_metrics_result = [
                'window' => $window,
                'series' => [],
                'available' => false,
                'note' => __('No backend or credential resolvable for this site.'),
            ];

            return;
        }

        try {
            $this->container_metrics_result = $backend->metrics($this->site, $credential, $window);
        } catch (\Throwable $e) {
            $this->container_metrics_result = [
                'window' => $window,
                'series' => [],
                'available' => false,
                'note' => __('Failed to fetch metrics: :err', ['err' => $e->getMessage()]),
            ];
        }
    }

    /**
     * Fetch the last N runtime (RUN) log lines via the site's cloud
     * backend. Backend results are 60s-cached. Never throws.
     */
    public function fetchContainerRuntimeLogs(): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('view', $this->site);

        $backend = CloudRouter::backendFor($this->site);
        $credential = CloudRouter::credentialFor($this->site);
        if ($backend === null || $credential === null) {
            $this->container_runtime_logs_result = [
                'lines' => [],
                'available' => false,
                'note' => __('No backend or credential resolvable for this site.'),
            ];

            return;
        }

        try {
            $this->container_runtime_logs_result = $backend->runtimeLogs($this->site, $credential, 200);
        } catch (\Throwable $e) {
            $this->container_runtime_logs_result = [
                'lines' => [],
                'available' => false,
                'note' => __('Failed to fetch runtime logs: :err', ['err' => $e->getMessage()]),
            ];
        }
    }

    public function rollbackContainerImage(string $image): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $image = trim($image);
        if ($image === '' || $image === $this->site->container_image) {
            if (method_exists($this, 'toastWarning')) {
                $this->toastWarning(__('Already on that image — nothing to roll back to.'));
            }

            return;
        }

        RedeployCloudSiteJob::dispatch($this->site->id, $image);
        $this->container_image_input = $image;

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Rollback to :image queued.', ['image' => $image]));
        }
    }

    /**
     * Attach the managed database currently picked in
     * $container_database_attach_id to this site. The job merges the
     * connection env vars into the site's env file and redeploys.
     */
    public function attachContainerDatabase(): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $databaseId = trim($this->container_database_attach_id);
        if ($databaseId === '') {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Pick a database to attach.'));
            }

            return;
        }

        $database = CloudDatabase::query()
            ->where('organization_id', $this->site->organization_id)
            ->where('status', CloudDatabase::STATUS_ACTIVE)
            ->find($databaseId);
        if ($database === null) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Database not found, not active, or not in this organization.'));
            }

            return;
        }

        AttachCloudDatabaseJob::dispatch($database->id, $this->site->id);
        $this->container_database_attach_id = '';

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Database attach queued. The connection env vars will be injected and the app redeployed.'));
        }
    }

    /**
     * Detach a managed database from this site. The job strips the
     * connection env keys it added and redeploys.
     */
    public function detachContainerDatabase(string $databaseId): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $database = CloudDatabase::query()
            ->where('organization_id', $this->site->organization_id)
            ->find($databaseId);
        if ($database === null) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Database not found or not in this organization.'));
            }

            return;
        }

        AttachCloudDatabaseJob::dispatch($database->id, $this->site->id, detach: true);

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Database detach queued. The connection env vars will be removed and the app redeployed.'));
        }
    }

    /**
     * Whether the site's container backend can run background workers.
     * App Runner cannot — the dashboard renders a disabled state for it.
     */
    public function containerSupportsWorkers(): bool
    {
        $backend = CloudRouter::backendFor($this->site);

        return $backend !== null && $backend->supportsWorkers();
    }

    /**
     * Add a queue worker to this site using the add-worker form inputs.
     * Creates a CloudWorker row and queues SyncCloudWorkersJob.
     */
    public function addContainerWorker(): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        try {
            (new CreateCloudWorker)->handle($this->site, [
                'type' => CloudWorker::TYPE_WORKER,
                'command' => trim($this->container_worker_command_input),
                'size' => $this->container_worker_size_input,
                'instance_count' => $this->container_worker_count_input,
            ]);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        $this->container_worker_command_input = '';
        $this->container_worker_size_input = 'small';
        $this->container_worker_count_input = 1;

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Worker added. The backend spec is being updated and a fresh roll queued.'));
        }
    }

    /**
     * Enable the Laravel scheduler — creates a scheduler-type
     * CloudWorker (one instance running `php artisan schedule:work`).
     */
    public function enableContainerScheduler(): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        try {
            (new CreateCloudWorker)->handle($this->site, [
                'type' => CloudWorker::TYPE_SCHEDULER,
            ]);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Scheduler enabled. The backend spec is being updated and a fresh roll queued.'));
        }
    }

    /** Disable the scheduler — removes the scheduler-type CloudWorker. */
    public function disableContainerScheduler(): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $scheduler = CloudWorker::query()
            ->where('site_id', $this->site->id)
            ->where('type', CloudWorker::TYPE_SCHEDULER)
            ->first();
        if ($scheduler === null) {
            return;
        }

        $scheduler->delete();
        SyncCloudWorkersJob::dispatch($this->site->id);

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Scheduler disabled. The backend will drop it on the next roll.'));
        }
    }

    /** Scale a worker's instance count, then re-sync the backend spec. */
    public function scaleContainerWorker(string $workerId, int $count): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $worker = CloudWorker::query()
            ->where('site_id', $this->site->id)
            ->find($workerId);
        if ($worker === null) {
            return;
        }

        if ($worker->isScheduler()) {
            if (method_exists($this, 'toastWarning')) {
                $this->toastWarning(__('The scheduler always runs a single instance.'));
            }

            return;
        }

        $worker->update(['instance_count' => max(1, $count)]);
        SyncCloudWorkersJob::dispatch($this->site->id);

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Worker scaled to :n instance(s). Re-sync queued.', ['n' => $worker->effectiveInstanceCount()]));
        }
    }

    /** Remove a worker, then re-sync the backend spec without it. */
    public function removeContainerWorker(string $workerId): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $worker = CloudWorker::query()
            ->where('site_id', $this->site->id)
            ->find($workerId);
        if ($worker === null) {
            return;
        }

        $worker->delete();
        SyncCloudWorkersJob::dispatch($this->site->id);

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Worker removed. The backend will drop the component on the next roll.'));
        }
    }

    /* ========================================================================
     * Scaling & health — autoscaling rules + HTTP health checks
     * ======================================================================== */

    /**
     * Whether the site's container backend can apply autoscaling +
     * health-check config. DigitalOcean App Platform and App Runner
     * both can; the dashboard shows a degradation note for App Runner.
     */
    public function containerSupportsAutoscaling(): bool
    {
        $backend = CloudRouter::backendFor($this->site);

        return $backend !== null && $backend->supportsAutoscaling();
    }

    /**
     * Persist the autoscaling form inputs via ConfigureCloudAutoscaling
     * and queue the backend spec sync.
     */
    public function saveContainerAutoscaling(): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        try {
            (new ConfigureCloudAutoscaling)->handle($this->site, [
                'enabled' => $this->container_autoscaling_enabled,
                'min_instances' => $this->container_autoscaling_min,
                'max_instances' => $this->container_autoscaling_max,
                'cpu_percent' => $this->container_autoscaling_cpu,
            ]);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess($this->container_autoscaling_enabled
                ? __('Autoscaling saved. It supersedes the fixed instance count — a fresh roll is queued.')
                : __('Autoscaling disabled. The site reverts to its fixed instance count — a fresh roll is queued.'));
        }
    }

    /**
     * Persist the health-check form inputs via ConfigureCloudHealthCheck
     * and queue the backend spec sync.
     */
    public function saveContainerHealthCheck(): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        try {
            (new ConfigureCloudHealthCheck)->handle($this->site, [
                'enabled' => $this->container_health_check_enabled,
                'http_path' => $this->container_health_check_path,
                'initial_delay_seconds' => $this->container_health_check_initial_delay,
                'period_seconds' => $this->container_health_check_period,
                'timeout_seconds' => $this->container_health_check_timeout,
                'success_threshold' => $this->container_health_check_success,
                'failure_threshold' => $this->container_health_check_failure,
            ]);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess($this->container_health_check_enabled
                ? __('Health check saved. The backend spec is being updated and a fresh roll queued.')
                : __('Health check disabled. The backend will drop it on the next roll.'));
        }
    }

    public function refreshHybridStackStatus(): void
    {
        if (! $this->site->usesContainerRuntime()) {
            return;
        }

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $container = is_array($meta['container'] ?? null) ? $meta['container'] : [];
        $stack = is_array($container['hybrid_edge_stack'] ?? null) ? $container['hybrid_edge_stack'] : [];
        if ($stack === []) {
            return;
        }

        $status = (string) ($stack['status'] ?? '');
        if (in_array($status, ['complete', 'failed'], true)) {
            return;
        }

        $this->site->refresh();
    }
}
