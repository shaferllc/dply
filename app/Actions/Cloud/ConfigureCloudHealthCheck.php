<?php

declare(strict_types=1);

namespace App\Actions\Cloud;

use App\Jobs\SyncCloudScalingJob;
use App\Models\Site;
use App\Services\Cloud\CloudRouter;
use App\Services\Cloud\CloudScalingConfig;
use InvalidArgumentException;
use RuntimeException;

/**
 * Configure the HTTP health check for a Cloud container site.
 *
 * Validates the health-check payload (path starts with "/", all
 * thresholds positive), persists it to meta.container.health_check,
 * and dispatches SyncCloudScalingJob to push the change to the
 * backend's deployment spec.
 *
 * When enabled the backend emits a `health_check` block on the web
 * service; when disabled the block is omitted on the next push.
 *
 * Guards:
 *  - the site must be a Cloud container site;
 *  - the site's backend must support autoscaling / health checks.
 */
class ConfigureCloudHealthCheck
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{enabled: bool, http_path: string, initial_delay_seconds: int, period_seconds: int, timeout_seconds: int, success_threshold: int, failure_threshold: int}
     */
    public function handle(Site $site, array $payload): array
    {
        if (! is_string($site->container_backend) || $site->container_backend === '') {
            throw new InvalidArgumentException('Health checks can only be configured on Cloud container sites.');
        }

        $backend = CloudRouter::backendFor($site);
        if ($backend === null) {
            throw new RuntimeException('No cloud backend is resolvable for this site.');
        }
        if (! $backend->supportsAutoscaling()) {
            throw new InvalidArgumentException('This site\'s backend does not support health checks.');
        }

        // Merge over the site's current config so a partial payload
        // (e.g. just toggling enabled) keeps the existing thresholds.
        $current = CloudScalingConfig::healthCheck($site);
        $config = CloudScalingConfig::validateHealthCheck(array_merge($current, $payload));

        CloudScalingConfig::persistHealthCheck($site, $config);

        SyncCloudScalingJob::dispatch($site->id);

        return $config;
    }
}
