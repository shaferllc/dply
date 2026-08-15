<?php

declare(strict_types=1);

namespace App\Livewire\Cloud\Concerns;

use App\Models\CloudBucket;
use App\Models\CloudDeployTask;
use App\Models\CloudWorker;
use App\Models\ProviderCredential;
use App\Modules\Billing\Services\ManagedProductCostEstimator;
use App\Modules\Cloud\Backends\AwsAppRunnerBackend;
use App\Modules\Cloud\Backends\DigitalOceanAppPlatformBackend;
use App\Modules\Cloud\Services\DigitalOceanAppPlatformService;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCloudCostBackend
{


    public function updatedBackend(string $value): void
    {
        $regions = $this->backendRegions($value);
        if ($regions !== [] && ($this->region === '' || ! in_array($this->region, array_column($regions, 'slug'), true))) {
            $this->region = $regions[0]['slug'];
        }

        // App Runner has no DO managed-DB create path — convert pending
        // "create" rows to external connection mode (and back on DO).
        foreach ($this->databases as $i => $row) {
            $mode = (string) ($row['mode'] ?? '');
            if ($value === 'aws_app_runner' && $mode === 'create') {
                $this->databases[$i]['mode'] = 'external';
                $this->databases[$i]['ssl'] = $row['ssl'] ?? true;
                $this->databases[$i]['port'] = $row['port'] ?? (($row['engine'] ?? '') === 'mysql' ? 3306 : 5432);
            } elseif ($value !== 'aws_app_runner' && $mode === 'external') {
                $this->databases[$i]['mode'] = 'create';
                $this->databases[$i]['size'] = $row['size'] ?? 'small';
            }
        }
    }

    /**
     * Live cost preview + (for DO) spec validation via /apps/propose.
     * Called by the form's "Estimate" button and by the deploy()
     * pre-flight gate. Stores the estimate or the error on
     * $costPreview so the blade can show either a price or an inline
     * diagnostic.
     *
     * DO App Platform: POST /apps/propose (validation + provider cost).
     * AWS App Runner: local vCPU/GB-hour estimate (no propose API).
     */
    public function recomputeCostPreview(): void
    {
        if ($this->backend === 'aws_app_runner') {
            $this->costPreview = [
                'value' => $this->dplyResourceEstimateUsd(),
                'error' => null,
            ];

            return;
        }

        if ($this->backend !== 'digitalocean_app_platform') {
            $this->costPreview = ['value' => null, 'error' => null];

            return;
        }

        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return;
        }

        $credential = ProviderCredential::query()
            ->where('organization_id', $org->id)
            ->where('provider', 'digitalocean')
            ->orderBy('created_at')
            ->first();
        if ($credential === null) {
            $this->costPreview = ['value' => null, 'error' => null];

            return;
        }

        $sizeSlugMap = CloudDeployTask::SIZE_TIERS;
        $sizeSlug = $sizeSlugMap[$this->size_tier] ?? 'basic-xxs';

        $payload = [
            'name' => $this->name,
            'region' => $this->region,
            'size_tier_slug' => $sizeSlug,
            'instances' => $this->instances,
            'port' => $this->port,
            'mode' => $this->mode,
            'image' => $this->image,
            'repo' => $this->repo,
            'branch' => $this->branch,
            'dockerfile_path' => $this->dockerfile_path,
            'autoscaling' => $this->autoscaling_enabled ? [
                'enabled' => true,
                'min_instances' => $this->autoscaling_min,
                'max_instances' => $this->autoscaling_max,
                'cpu_percent' => $this->autoscaling_cpu_percent,
            ] : null,
            'health_check' => $this->health_check_enabled ? [
                'enabled' => true,
                'http_path' => $this->health_check_path,
                'period_seconds' => $this->health_check_period_seconds,
                'timeout_seconds' => $this->health_check_timeout_seconds,
                'failure_threshold' => $this->health_check_failure_threshold,
            ] : null,
        ];

        $spec = DigitalOceanAppPlatformBackend::buildProposeSpecFromPayload($payload);

        try {
            $result = (new DigitalOceanAppPlatformService($credential))->proposeApp($spec);
            $this->costPreview = [
                'value' => $result['app_cost'],
                'error' => $result['error'],
            ];
        } catch (\Throwable $e) {
            // Network blip / unexpected response — surface as a soft
            // warning so the user can still submit. Real errors land
            // in `error` from DO's structured response above.
            $this->costPreview = ['value' => null, 'error' => null];
            report($e);
        }
    }

    /**
     * Monthly resource estimate (USD) for the form — shown next to the
     * flat platform fee in the create sidebar.
     *
     * DO App Platform: dply-metered marked-up container/workers/DBs
     * (matches the invoice; not DO's raw propose cost).
     *
     * AWS App Runner: estimated AWS compute the customer pays AWS
     * directly (vCPU/GB-hour × hours × instances). Not dply-metered.
     */
    public function dplyResourceEstimateUsd(): float
    {
        $estimator = app(ManagedProductCostEstimator::class);

        if ($this->backend === 'aws_app_runner') {
            $instances = $this->autoscaling_enabled
                ? max(1, (int) $this->autoscaling_min)
                : max(1, (int) $this->instances);

            return $estimator->appRunnerMonthlyUsd($this->size_tier, $instances);
        }

        $total = $estimator->cloudContainerPrice($this->size_tier) * max(1, $this->instances);

        foreach ($this->workers as $worker) {
            $size = (string) ($worker['size'] ?? 'small');
            $count = (int) ($worker['instance_count'] ?? 1);
            $isScheduler = (string) ($worker['type'] ?? '') === CloudWorker::TYPE_SCHEDULER;
            $total += $estimator->cloudContainerPrice($size) * ($isScheduler ? 1 : max(1, $count));
        }

        foreach ($this->databases as $database) {
            // Attached existing databases are already billed on the org; only
            // newly created ones add cost from this form.
            if ((string) ($database['mode'] ?? 'create') !== 'create') {
                continue;
            }
            $total += $estimator->cloudDatabasePrice((string) ($database['size'] ?? 'small'));
        }

        return $total;
    }

    /**
     * @return list<array{slug: string, label: string}>
     */
    private function backendRegions(string $backend): array
    {
        return match ($backend) {
            // The old `::class === '' ? [] : …` guard compared a class-string
            // literal to '', which is always false — it never did anything.
            'digitalocean_app_platform' => (new DigitalOceanAppPlatformBackend)->regions(),
            'aws_app_runner' => (new AwsAppRunnerBackend)->regions(),
            default => $this->mergedRegions(),
        };
    }

    /**
     * @return list<array{slug: string, label: string}>
     */
    private function mergedRegions(): array
    {
        $merged = [];
        foreach ((new DigitalOceanAppPlatformBackend)->regions() as $r) {
            $merged[$r['slug']] = ['slug' => $r['slug'], 'label' => 'DO · '.$r['label']];
        }
        foreach ((new AwsAppRunnerBackend)->regions() as $r) {
            $merged[$r['slug']] = ['slug' => $r['slug'], 'label' => 'AWS · '.$r['label']];
        }

        return array_values($merged);
    }

    /**
     * @return array<string, mixed>
     */
    private function extrasPayload(): array
    {
        $extras = [];

        if ($this->workers !== []) {
            $extras['workers'] = array_map(static fn (array $w): array => [
                'type' => (string) ($w['type'] ?? CloudWorker::TYPE_WORKER),
                'name' => (string) ($w['name'] ?? ''),
                'command' => (string) ($w['command'] ?? ''),
                'size' => (string) ($w['size'] ?? 'small'),
                'instance_count' => (int) ($w['instance_count'] ?? 1),
            ], $this->workers);
        }

        $tasksPayload = [];
        if ($this->migrations_enabled && trim($this->migrations_command) !== '') {
            $tasksPayload[] = [
                'trigger' => CloudDeployTask::TRIGGER_PRE_DEPLOY,
                'name' => CloudDeployTask::NAME_MIGRATE,
                'command' => $this->migrations_command,
                'size' => 'small',
            ];
        }
        foreach ($this->deploy_tasks as $task) {
            $command = trim((string) ($task['command'] ?? ''));
            if ($command === '') {
                continue;
            }
            $tasksPayload[] = [
                'trigger' => (string) ($task['trigger'] ?? CloudDeployTask::TRIGGER_PRE_DEPLOY),
                'name' => (string) ($task['name'] ?? ''),
                'command' => $command,
                'size' => (string) ($task['size'] ?? 'small'),
            ];
        }
        if ($tasksPayload !== []) {
            $extras['deploy_tasks'] = $tasksPayload;
        }

        if ($this->autoscaling_enabled) {
            $extras['autoscaling'] = [
                'enabled' => true,
                'min_instances' => $this->autoscaling_min,
                'max_instances' => $this->autoscaling_max,
                'cpu_percent' => $this->autoscaling_cpu_percent,
            ];
        }

        if ($this->health_check_enabled) {
            $extras['health_check'] = [
                'enabled' => true,
                'http_path' => $this->health_check_path,
                'period_seconds' => $this->health_check_period_seconds,
                'timeout_seconds' => $this->health_check_timeout_seconds,
                'failure_threshold' => $this->health_check_failure_threshold,
            ];
        }

        if ($this->databases !== []) {
            $extras['databases'] = array_map(function (array $row): array {
                $mode = (string) ($row['mode'] ?? 'create');
                $base = [
                    'mode' => $mode,
                    'name' => (string) ($row['name'] ?? ''),
                    'env_prefix' => strtoupper((string) ($row['env_prefix'] ?? 'DB')),
                ];
                if ($mode === 'attach') {
                    $base['cloud_database_id'] = (string) ($row['cloud_database_id'] ?? '');

                    return $base;
                }
                if ($mode === 'external') {
                    return $base + [
                        'engine' => (string) ($row['engine'] ?? 'postgres'),
                        'host' => (string) ($row['host'] ?? ''),
                        'port' => (int) ($row['port'] ?? 0),
                        'database' => (string) ($row['database'] ?? ''),
                        'username' => (string) ($row['username'] ?? ''),
                        'password' => (string) ($row['password'] ?? ''),
                        'ssl' => filter_var($row['ssl'] ?? true, FILTER_VALIDATE_BOOLEAN),
                        'version' => (string) ($row['version'] ?? ''),
                        'region' => 'external',
                    ];
                }

                return $base + [
                    'engine' => (string) ($row['engine'] ?? 'postgres'),
                    'size' => (string) ($row['size'] ?? 'small'),
                    'version' => (string) ($row['version'] ?? ''),
                    'region' => $this->region,
                ];
            }, $this->databases);
        }

        if ($this->buckets !== []) {
            $extras['buckets'] = array_map(function (array $row): array {
                return [
                    'name' => (string) ($row['name'] ?? ''),
                    'backend' => (string) ($row['backend'] ?? CloudBucket::BACKEND_DIGITALOCEAN_SPACES),
                    'region' => (string) ($row['region'] ?? $this->region),
                    'env_prefix' => strtoupper((string) ($row['env_prefix'] ?? 'S3')),
                ];
            }, $this->buckets);
        }

        if ($this->domains !== []) {
            $extras['domains'] = $this->domains;
        }

        // Alerts always emitted — defaults match CloudAlerts so the
        // payload is harmless even when the user never opens the
        // section. Per-site override only when explicitly enabled.
        $alerts = [
            'deployment_failed' => ['enabled' => $this->alert_deployment_failed_enabled],
            'restart_count' => ['enabled' => $this->alert_restart_count_enabled, 'value' => $this->alert_restart_count_value, 'window' => 'FIVE_MINUTES'],
            'cpu_utilization' => ['enabled' => $this->alert_cpu_enabled, 'value' => $this->alert_cpu_value, 'window' => 'FIVE_MINUTES'],
            'mem_utilization' => ['enabled' => $this->alert_mem_enabled, 'value' => $this->alert_mem_value, 'window' => 'FIVE_MINUTES'],
        ];
        if ($this->alert_destinations_override_enabled) {
            $emails = array_values(array_filter(array_map(
                'trim',
                preg_split('/[\s,]+/', $this->alert_destinations_override_emails) ?: [],
            )));
            $alerts['destinations_override'] = [
                'slack_webhook_url' => trim($this->alert_destinations_override_slack) ?: null,
                'emails' => $emails,
            ];
        }
        $extras['alerts'] = $alerts;

        return $extras;
    }
}
