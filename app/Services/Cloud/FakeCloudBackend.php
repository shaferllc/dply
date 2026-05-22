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

    public function syncWorkers(Site $site, ProviderCredential $credential): void
    {
        // No-op — CloudWorker rows live in the dply database; there's
        // no real backend spec to push in fake mode.
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

    private function syntheticLiveUrl(Site $site): string
    {
        $slug = $site->slug ?: Str::slug($site->name) ?: 'app';

        return 'https://'.$slug.'.fake-edge.dply.local';
    }
}
