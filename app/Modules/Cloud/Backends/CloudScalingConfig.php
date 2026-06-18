<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Backends;

use App\Models\Site;
use InvalidArgumentException;

/**
 * Value object that reads / writes / validates the autoscaling and
 * HTTP health-check configuration that lives on a Cloud site's
 * meta.container.
 *
 * Two independent blocks:
 *
 *   meta.container.autoscaling = {
 *     enabled: bool, min_instances: int, max_instances: int,
 *     cpu_percent: int (1-100),
 *   }
 *
 *   meta.container.health_check = {
 *     enabled: bool, http_path: string,
 *     initial_delay_seconds, period_seconds, timeout_seconds,
 *     success_threshold, failure_threshold,
 *   }
 *
 * When autoscaling is enabled the backend emits an `autoscaling`
 * block on the web service component and OMITS the fixed
 * `instance_count` — the two are mutually exclusive on DigitalOcean
 * App Platform. When disabled, the site keeps its fixed instance
 * count (meta.container.instance_count, set via dply:cloud:scale).
 *
 * All reads default sensibly so callers never have to null-check —
 * a brand-new site behaves as "autoscaling off, health check off".
 */
class CloudScalingConfig
{
    /** Autoscaling defaults — a gentle 1→3 instance, 80% CPU target. */
    public const DEFAULT_MIN_INSTANCES = 1;

    public const DEFAULT_MAX_INSTANCES = 3;

    public const DEFAULT_CPU_PERCENT = 80;

    /** Health-check defaults — match DO App Platform's own defaults. */
    public const DEFAULT_HEALTH_PATH = '/';

    public const DEFAULT_INITIAL_DELAY_SECONDS = 0;

    public const DEFAULT_PERIOD_SECONDS = 10;

    public const DEFAULT_TIMEOUT_SECONDS = 1;

    public const DEFAULT_SUCCESS_THRESHOLD = 1;

    public const DEFAULT_FAILURE_THRESHOLD = 9;

    /**
     * Read the autoscaling config for a site, with defaults applied.
     *
     * @return array{enabled: bool, min_instances: int, max_instances: int, cpu_percent: int}
     */
    public static function autoscaling(Site $site): array
    {
        $meta = ($site->meta );
        $raw = $meta['container']['autoscaling'] ?? [];
        if (! is_array($raw)) {
            $raw = [];
        }

        $min = is_int($raw['min_instances'] ?? null) ? (int) $raw['min_instances'] : self::DEFAULT_MIN_INSTANCES;
        $max = is_int($raw['max_instances'] ?? null) ? (int) $raw['max_instances'] : self::DEFAULT_MAX_INSTANCES;
        $cpu = is_int($raw['cpu_percent'] ?? null) ? (int) $raw['cpu_percent'] : self::DEFAULT_CPU_PERCENT;

        return [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'min_instances' => max(1, $min),
            'max_instances' => max(1, $max),
            'cpu_percent' => max(1, min(100, $cpu)),
        ];
    }

    /**
     * Whether autoscaling is enabled for the site. When true the
     * fixed instance_count is superseded.
     */
    public static function autoscalingEnabled(Site $site): bool
    {
        return self::autoscaling($site)['enabled'] === true;
    }

    /**
     * Read the health-check config for a site, with defaults applied.
     *
     * @return array{enabled: bool, http_path: string, initial_delay_seconds: int, period_seconds: int, timeout_seconds: int, success_threshold: int, failure_threshold: int}
     */
    public static function healthCheck(Site $site): array
    {
        $meta = ($site->meta );
        $raw = $meta['container']['health_check'] ?? [];
        if (! is_array($raw)) {
            $raw = [];
        }

        $path = is_string($raw['http_path'] ?? null) && $raw['http_path'] !== ''
            ? (string) $raw['http_path']
            : self::DEFAULT_HEALTH_PATH;

        return [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'http_path' => $path,
            'initial_delay_seconds' => self::intOr($raw['initial_delay_seconds'] ?? null, self::DEFAULT_INITIAL_DELAY_SECONDS, 0),
            'period_seconds' => self::intOr($raw['period_seconds'] ?? null, self::DEFAULT_PERIOD_SECONDS, 1),
            'timeout_seconds' => self::intOr($raw['timeout_seconds'] ?? null, self::DEFAULT_TIMEOUT_SECONDS, 1),
            'success_threshold' => self::intOr($raw['success_threshold'] ?? null, self::DEFAULT_SUCCESS_THRESHOLD, 1),
            'failure_threshold' => self::intOr($raw['failure_threshold'] ?? null, self::DEFAULT_FAILURE_THRESHOLD, 1),
        ];
    }

    public static function healthCheckEnabled(Site $site): bool
    {
        return self::healthCheck($site)['enabled'] === true;
    }

    /**
     * Validate and normalize an autoscaling config payload.
     * Throws InvalidArgumentException on any out-of-range value.
     *
     * @param  array<string, mixed> $input
     * @return array{enabled: bool, min_instances: int, max_instances: int, cpu_percent: int}
     */
    public static function validateAutoscaling(array $input): array
    {
        $enabled = (bool) ($input['enabled'] ?? false);
        $min = (int) ($input['min_instances'] ?? self::DEFAULT_MIN_INSTANCES);
        $max = (int) ($input['max_instances'] ?? self::DEFAULT_MAX_INSTANCES);
        $cpu = (int) ($input['cpu_percent'] ?? self::DEFAULT_CPU_PERCENT);

        if ($min < 1) {
            throw new InvalidArgumentException('Minimum instance count must be at least 1.');
        }
        if ($max < $min) {
            throw new InvalidArgumentException('Maximum instance count must be greater than or equal to the minimum.');
        }
        if ($max > 50) {
            throw new InvalidArgumentException('Maximum instance count must not exceed 50.');
        }
        if ($cpu < 1 || $cpu > 100) {
            throw new InvalidArgumentException('CPU target percent must be between 1 and 100.');
        }

        return [
            'enabled' => $enabled,
            'min_instances' => $min,
            'max_instances' => $max,
            'cpu_percent' => $cpu,
        ];
    }

    /**
     * Validate and normalize a health-check config payload.
     * Throws InvalidArgumentException on any invalid value.
     *
     * @param  array<string, mixed> $input
     * @return array{enabled: bool, http_path: string, initial_delay_seconds: int, period_seconds: int, timeout_seconds: int, success_threshold: int, failure_threshold: int}
     */
    public static function validateHealthCheck(array $input): array
    {
        $enabled = (bool) ($input['enabled'] ?? false);
        $path = trim((string) ($input['http_path'] ?? self::DEFAULT_HEALTH_PATH));
        if ($path === '') {
            $path = self::DEFAULT_HEALTH_PATH;
        }
        if (! str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Health-check path must start with "/".');
        }

        $fields = [
            'initial_delay_seconds' => [(int) ($input['initial_delay_seconds'] ?? self::DEFAULT_INITIAL_DELAY_SECONDS), 0, 'Initial delay'],
            'period_seconds' => [(int) ($input['period_seconds'] ?? self::DEFAULT_PERIOD_SECONDS), 1, 'Period'],
            'timeout_seconds' => [(int) ($input['timeout_seconds'] ?? self::DEFAULT_TIMEOUT_SECONDS), 1, 'Timeout'],
            'success_threshold' => [(int) ($input['success_threshold'] ?? self::DEFAULT_SUCCESS_THRESHOLD), 1, 'Success threshold'],
            'failure_threshold' => [(int) ($input['failure_threshold'] ?? self::DEFAULT_FAILURE_THRESHOLD), 1, 'Failure threshold'],
        ];

        $out = ['enabled' => $enabled, 'http_path' => $path];
        foreach ($fields as $key => [$value, $floor, $label]) {
            if ($value < $floor) {
                throw new InvalidArgumentException(sprintf('%s must be at least %d.', $label, $floor));
            }
            $out[$key] = $value;
        }

        /** @var array{enabled: bool, http_path: string, initial_delay_seconds: int, period_seconds: int, timeout_seconds: int, success_threshold: int, failure_threshold: int} $out */
        return $out;
    }

    /**
     * Persist a validated autoscaling config onto the site's meta.
     *
     * @param  array{enabled: bool, min_instances: int, max_instances: int, cpu_percent: int}  $config
     */
    public static function persistAutoscaling(Site $site, array $config): void
    {
        $meta = ($site->meta );
        $container = is_array($meta['container'] ?? null) ? $meta['container'] : [];
        $container['autoscaling'] = $config;
        $meta['container'] = $container;
        $site->update(['meta' => $meta]);
    }

    /**
     * Persist a validated health-check config onto the site's meta.
     *
     * @param  array{enabled: bool, http_path: string, initial_delay_seconds: int, period_seconds: int, timeout_seconds: int, success_threshold: int, failure_threshold: int}  $config
     */
    public static function persistHealthCheck(Site $site, array $config): void
    {
        $meta = ($site->meta );
        $container = is_array($meta['container'] ?? null) ? $meta['container'] : [];
        $container['health_check'] = $config;
        $meta['container'] = $container;
        $site->update(['meta' => $meta]);
    }

    /**
     * Build the DigitalOcean App Platform `autoscaling` spec block
     * from the site's config, or null when autoscaling is disabled.
     *
     * Shape:
     *   { min_instance_count, max_instance_count,
     *     metrics: { cpu: { percent } } }
     *
     * @return array<string, mixed>|null
     */
    public static function doAutoscalingBlock(Site $site): ?array
    {
        $cfg = self::autoscaling($site);
        if (! $cfg['enabled']) {
            return null;
        }

        return [
            'min_instance_count' => $cfg['min_instances'],
            'max_instance_count' => max($cfg['min_instances'], $cfg['max_instances']),
            'metrics' => [
                'cpu' => [
                    'percent' => $cfg['cpu_percent'],
                ],
            ],
        ];
    }

    /**
     * Build the DigitalOcean App Platform service `health_check`
     * spec block from the site's config, or null when disabled.
     *
     * @return array<string, mixed>|null
     */
    public static function doHealthCheckBlock(Site $site): ?array
    {
        $cfg = self::healthCheck($site);
        if (! $cfg['enabled']) {
            return null;
        }

        return [
            'http_path' => $cfg['http_path'],
            'initial_delay_seconds' => $cfg['initial_delay_seconds'],
            'period_seconds' => $cfg['period_seconds'],
            'timeout_seconds' => $cfg['timeout_seconds'],
            'success_threshold' => $cfg['success_threshold'],
            'failure_threshold' => $cfg['failure_threshold'],
        ];
    }

    private static function intOr(mixed $value, int $default, int $floor): int
    {
        if (! is_int($value)) {
            return $default;
        }

        return max($floor, $value);
    }
}
