<?php

declare(strict_types=1);

namespace App\Services\Cloud\Concerns;

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Services\Cloud\CloudBackend;
use App\Services\DigitalOceanAppPlatformService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ReadsDoAppState
{


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
}
