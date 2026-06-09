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
 * Configure CPU-target autoscaling for a Cloud container site.
 *
 * Validates the autoscaling payload (min >= 1, max >= min,
 * cpu_percent 1-100), persists it to meta.container.autoscaling,
 * and dispatches SyncCloudScalingJob to push the change to the
 * backend's deployment spec.
 *
 * When autoscaling is enabled the backend emits an `autoscaling`
 * block and OMITS the fixed `instance_count` — the two are mutually
 * exclusive. When disabled the site reverts to its fixed instance
 * count (meta.container.instance_count, via dply:cloud:scale).
 *
 * Guards:
 *  - the site must be a Cloud container site;
 *  - the site's backend must support autoscaling.
 */
class ConfigureCloudAutoscaling
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{enabled: bool, min_instances: int, max_instances: int, cpu_percent: int}
     */
    public function handle(Site $site, array $payload): array
    {
        if (! is_string($site->container_backend) || $site->container_backend === '') {
            throw new InvalidArgumentException('Autoscaling can only be configured on Cloud container sites.');
        }

        $backend = CloudRouter::backendFor($site);
        if ($backend === null) {
            throw new RuntimeException('No cloud backend is resolvable for this site.');
        }
        if (! $backend->supportsAutoscaling()) {
            throw new InvalidArgumentException('This site\'s backend does not support autoscaling.');
        }

        // Merge over the site's current config so a partial payload
        // (e.g. just toggling enabled) keeps the existing min/max/cpu.
        $current = CloudScalingConfig::autoscaling($site);
        $config = CloudScalingConfig::validateAutoscaling(array_merge($current, $payload));

        CloudScalingConfig::persistAutoscaling($site, $config);

        SyncCloudScalingJob::dispatch($site->id);

        return $config;
    }
}
