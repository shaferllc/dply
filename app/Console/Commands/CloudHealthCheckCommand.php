<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Cloud\ConfigureCloudHealthCheck;
use App\Models\Site;
use Illuminate\Console\Command;
use Throwable;

/**
 * Configure / disable the HTTP health check for a Cloud site.
 *
 *   dply:cloud:healthcheck --site=<id|slug|name> --path=/health
 *   dply:cloud:healthcheck --site=<id|slug|name> --off
 *
 * Optional threshold flags map onto the backend's health-check spec:
 *   --initial-delay --period --timeout --success --failure
 *
 * Persists meta.container.health_check and queues SyncCloudScalingJob,
 * which pushes the health-check block into the backend's app spec and
 * rolls a deploy.
 */
class CloudHealthCheckCommand extends Command
{
    protected $signature = 'dply:cloud:healthcheck
        {--site= : Site ID, slug, or name}
        {--path= : HTTP path to probe (must start with "/")}
        {--initial-delay= : Seconds to wait before the first probe}
        {--period= : Seconds between probes}
        {--timeout= : Per-probe timeout in seconds}
        {--success= : Consecutive successes to mark healthy}
        {--failure= : Consecutive failures to mark unhealthy}
        {--off : Disable the health check}';

    protected $description = 'Configure or disable the HTTP health check for a Cloud container site.';

    public function handle(): int
    {
        $needle = trim((string) $this->option('site'));
        if ($needle === '') {
            $this->error('--site=<id|slug|name> is required.');

            return self::FAILURE;
        }

        $site = Site::query()
            ->where('id', $needle)
            ->orWhere('slug', $needle)
            ->orWhere('name', $needle)
            ->first();
        if ($site === null) {
            $this->error("Site not found: {$needle}");

            return self::FAILURE;
        }

        if (! is_string($site->container_backend) || $site->container_backend === '') {
            $this->error("Site {$site->name} is not a cloud container site.");

            return self::FAILURE;
        }

        $payload = [];
        if ($this->option('off')) {
            $payload['enabled'] = false;
        } else {
            $payload['enabled'] = true;
            $path = $this->option('path');
            if (is_string($path) && $path !== '') {
                $payload['http_path'] = $path;
            }
            $thresholds = [
                'initial-delay' => 'initial_delay_seconds',
                'period' => 'period_seconds',
                'timeout' => 'timeout_seconds',
                'success' => 'success_threshold',
                'failure' => 'failure_threshold',
            ];
            foreach ($thresholds as $opt => $key) {
                $value = $this->option($opt);
                if (is_string($value) && $value !== '') {
                    $payload[$key] = (int) $value;
                }
            }
        }

        try {
            $config = (new ConfigureCloudHealthCheck)->handle($site, $payload);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $config['enabled']) {
            $this->info(sprintf('Health check disabled for %s. Sync queued.', $site->name));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Health check enabled for %s — probing %s every %ds (timeout %ds, %d failure(s) to unhealthy). Sync queued.',
            $site->name,
            $config['http_path'],
            $config['period_seconds'],
            $config['timeout_seconds'],
            $config['failure_threshold'],
        ));

        return self::SUCCESS;
    }
}
