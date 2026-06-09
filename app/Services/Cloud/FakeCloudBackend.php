<?php

declare(strict_types=1);

namespace App\Services\Cloud;

use App\Models\ProviderCredential;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Synthetic backend used when DPLY_FAKE_CLOUD_PROVISION=true and the
 * org has no real DigitalOcean App Platform / AWS App Runner credential
 * connected. Lets dev installs (and the test suite) click through
 * /cloud/create end to end without hitting real cloud APIs.
 *
 * No external I/O — every verb returns immediately. Backend ids are
 * synthetic UUIDs so persisted Site rows look right (so the UI / CLI
 * surfaces don't choke on null fields).
 *
 * The companion CloudRouter::backendFor() opts in to this backend
 * automatically when fake-cloud is enabled and no real credential
 * is available; production traffic is unaffected.
 */
class FakeCloudBackend implements CloudBackend
{
    use ResolvesMetricWindows;

    public function __construct(public string $providerKey = 'fake_edge') {}

    public function providerKey(): string
    {
        return $this->providerKey;
    }

    public function supportsWorkers(): bool
    {
        // Fake backend mirrors DigitalOcean App Platform (the v1
        // worker-capable backend) so dev installs can click through
        // the workers flow end to end.
        return true;
    }

    public function supportsDeployTasks(): bool
    {
        // Mirror DO App Platform so dev installs exercise the
        // deploy-task UI end to end without a real cloud account.
        return true;
    }

    public function supportsAlerts(): bool
    {
        // Mirror DO App Platform — surfaces the alerts UI in dev
        // without needing a real cloud account or live destinations.
        return true;
    }

    public function cancelInProgressDeployment(Site $site, ProviderCredential $credential): bool
    {
        // Fake backend doesn't track in-progress deploys, so cancel
        // is a no-op success — the UI flow still exercises end-to-end
        // without a real cloud account.
        return true;
    }

    public function syncWorkers(Site $site, ProviderCredential $credential): void
    {
        // No-op — CloudWorker rows live in the dply database; there's
        // no real backend spec to push in fake mode.
    }

    public function supportsAutoscaling(): bool
    {
        // Mirrors DigitalOcean App Platform — the autoscaling +
        // health-check capable v1 backend — so dev installs / the
        // test suite can click through the flow end to end.
        return true;
    }

    public function syncScaling(Site $site, ProviderCredential $credential): void
    {
        // No-op — autoscaling / health-check config lives on the Site
        // model's meta in fake mode; there's no real spec to push.
    }

    public function provision(Site $site, ProviderCredential $credential): array
    {
        return [
            'backend_id' => 'fake-app-'.Str::random(10),
            'live_url' => $this->syntheticLiveUrl($site),
        ];
    }

    public function provisionFromSource(Site $site, ProviderCredential $credential): array
    {
        return [
            'backend_id' => 'fake-app-src-'.Str::random(10),
            'live_url' => $this->syntheticLiveUrl($site),
        ];
    }

    public function redeploy(Site $site, ProviderCredential $credential): array
    {
        return ['deployment_id' => 'fake-deploy-'.Str::random(8)];
    }

    public function updateImage(Site $site, ProviderCredential $credential, string $image): void
    {
        // No-op — image change is reflected on the Site row by the
        // caller; nothing to push to a backend in fake mode.
    }

    public function updateEnvVars(Site $site, ProviderCredential $credential): void
    {
        // No-op — env vars live on the Site model in fake mode.
    }

    public function teardown(Site $site, ProviderCredential $credential): void
    {
        // No-op — idempotent.
    }

    public function inspect(Site $site, ProviderCredential $credential): array
    {
        return [
            'phase' => 'ACTIVE',
            'live_url' => $this->syntheticLiveUrl($site),
            'raw' => ['fake' => true],
        ];
    }

    /**
     * Mirror the union of DO + App Runner regions so the create form's
     * region picker shows a familiar list in fake mode.
     */
    public function regions(): array
    {
        return [
            ['slug' => 'nyc', 'label' => 'New York (fake)'],
            ['slug' => 'sfo', 'label' => 'San Francisco (fake)'],
            ['slug' => 'ams', 'label' => 'Amsterdam (fake)'],
            ['slug' => 'fra', 'label' => 'Frankfurt (fake)'],
        ];
    }

    public function attachDomain(Site $site, ProviderCredential $credential, string $hostname): array
    {
        return [[
            'name' => $hostname,
            'type' => 'CNAME',
            'value' => 'fake-edge.dply.local',
            'status' => 'PENDING_VALIDATION',
        ]];
    }

    public function detachDomain(Site $site, ProviderCredential $credential, string $hostname): void
    {
        // No-op.
    }

    public function latestDeploymentLogs(Site $site, ProviderCredential $credential): array
    {
        return [
            'content' => "fake-edge backend\n[build] resolved framework: laravel\n[build] composer install ok\n[deploy] starting container\n[deploy] healthcheck OK\n",
            'url' => null,
            'message' => null,
        ];
    }

    public function recentDeployments(Site $site, ProviderCredential $credential, int $limit = 10): array
    {
        $now = now();
        $entries = [];
        for ($i = 0; $i < min(3, $limit); $i++) {
            $entries[] = [
                'id' => 'fake-dep-'.($i + 1),
                'phase' => $i === 0 ? 'ACTIVE' : 'SUPERSEDED',
                'started_at' => $now->copy()->subHours($i * 2)->toIso8601String(),
                'finished_at' => $now->copy()->subHours($i * 2)->addMinutes(3)->toIso8601String(),
                'cause' => $i === 0 ? 'manual' : 'auto',
            ];
        }

        return $entries;
    }

    /**
     * Deterministic synthetic metric series. CPU / memory follow a
     * gentle sine wave seeded off the site id so the same site always
     * renders the same shape (stable test oracle); restarts are a
     * mostly-flat low integer series. ~60 points regardless of window.
     */
    public function metrics(Site $site, ProviderCredential $credential, string $window): array
    {
        $window = $this->normalizeWindow($window);
        [$start, $end] = $this->windowBounds($window);
        $points = 60;
        $step = max(1, (int) (($end - $start) / $points));
        $seed = crc32((string) $site->id);

        $cpu = [];
        $memory = [];
        $restarts = [];
        for ($i = 0; $i < $points; $i++) {
            $t = $start + ($i * $step);
            $phase = ($seed % 100) / 100;
            $cpuVal = 30.0 + 25.0 * sin(($i / 9.0) + $phase) + (($seed >> ($i % 7)) % 7);
            $memVal = 45.0 + 18.0 * sin(($i / 13.0) + $phase + 1.5) + (($seed >> ($i % 5)) % 5);
            $cpu[] = ['t' => $t, 'v' => round(max(0.0, min(100.0, $cpuVal)), 2)];
            $memory[] = ['t' => $t, 'v' => round(max(0.0, min(100.0, $memVal)), 2)];
            // A restart roughly every ~20 points.
            $restarts[] = ['t' => $t, 'v' => ($i > 0 && $i % 23 === (int) ($seed % 23)) ? 1.0 : 0.0];
        }

        return [
            'window' => $window,
            'series' => [
                'cpu' => $cpu,
                'memory' => $memory,
                'restarts' => $restarts,
            ],
            'available' => true,
        ];
    }

    /**
     * Deterministic synthetic runtime log lines — a small repeating
     * set of Laravel-shaped request lines so dev installs and tests
     * see a populated RUN log viewer.
     */
    public function runtimeLogs(Site $site, ProviderCredential $credential, int $lines = 200, string $component = 'web'): array
    {
        $lines = max(1, min(2000, $lines));
        $samples = [
            '[info] Application started — listening on 0.0.0.0:8080',
            'production.INFO: Queue worker booted',
            '200 GET / — 14ms',
            '200 GET /health — 2ms',
            '302 POST /login — 41ms',
            '200 GET /dashboard — 88ms',
            'production.INFO: Scheduled task ran: cleanup:expired',
            '404 GET /favicon.ico — 1ms',
        ];

        $out = [];
        $count = min($lines, 40);
        for ($i = 0; $i < $count; $i++) {
            $out[] = '[fake-edge] '.$samples[$i % count($samples)];
        }

        return [
            'lines' => $out,
            'available' => true,
        ];
    }

    private function syntheticLiveUrl(Site $site): string
    {
        $slug = $site->slug ?: Str::slug($site->name) ?: 'app';

        return 'https://'.$slug.'.fake-edge.dply.local';
    }
}
