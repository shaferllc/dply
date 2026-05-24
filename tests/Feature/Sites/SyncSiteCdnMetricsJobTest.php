<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\SyncSiteCdnMetricsJobTest;

use App\Jobs\SyncSiteCdnMetricsJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * @return array{0: Site, 1: ProviderCredential}
 */
function seedMetricsSite(array $cdnOverrides = []): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id, 'organization_id' => $org->id, 'ip_address' => '203.0.113.10',
    ]);
    $credential = ProviderCredential::query()->create([
        'user_id' => $user->id, 'organization_id' => $org->id,
        'provider' => 'cloudflare', 'name' => 'CF',
        'credentials' => ['api_token' => 'tok'],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id, 'organization_id' => $org->id,
        'meta' => ['cdn' => array_merge([
            'enabled' => true,
            'provider' => 'cloudflare',
            'credential_id' => $credential->id,
            'zone_id' => 'zone-1',
            'zone_name' => 'example.com',
            'hostname' => 'app.example.com',
            'origin_ip' => '203.0.113.10',
        ], $cdnOverrides)],
    ]);

    return [$site, $credential];
}

test('persists totals, derived hit rate, and polled timestamp', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/analytics/dashboard*' => Http::response([
            'success' => true,
            'result' => ['totals' => [
                'requests' => ['all' => 2000, 'cached' => 1500],
                'bandwidth' => ['all' => 10_000_000, 'cached' => 7_500_000],
            ]],
        ]),
    ]);

    [$site] = seedMetricsSite();
    (new SyncSiteCdnMetricsJob($site->id))->handle();

    $metrics = $site->fresh()->meta['cdn']['metrics'];
    expect($metrics['requests_all'])->toBe(2000);
    expect($metrics['requests_cached'])->toBe(1500);
    expect($metrics['hit_rate'])->toBe(0.75);
    expect($metrics['bandwidth_all'])->toBe(10_000_000);
    expect($metrics['last_polled_at'] ?? null)->not->toBeNull();
    expect($metrics['last_error'])->toBeNull();
});

test('hit rate is null when there are zero requests', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/analytics/dashboard*' => Http::response([
            'success' => true,
            'result' => ['totals' => [
                'requests' => ['all' => 0, 'cached' => 0],
                'bandwidth' => ['all' => 0, 'cached' => 0],
            ]],
        ]),
    ]);

    [$site] = seedMetricsSite();
    (new SyncSiteCdnMetricsJob($site->id))->handle();

    expect($site->fresh()->meta['cdn']['metrics']['hit_rate'])->toBeNull();
});

test('skips when cdn disabled', function () {
    Http::fake();
    [$site] = seedMetricsSite(['enabled' => false]);

    (new SyncSiteCdnMetricsJob($site->id))->handle();

    Http::assertNothingSent();
    expect($site->fresh()->meta['cdn']['metrics'] ?? null)->toBeNull();
});

test('skips when zone id not resolved yet', function () {
    Http::fake();
    [$site] = seedMetricsSite(['zone_id' => '']);

    (new SyncSiteCdnMetricsJob($site->id))->handle();

    Http::assertNothingSent();
});

test('records error and rethrows on api failure', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/analytics/dashboard*' => Http::response([
            'success' => false, 'errors' => [['message' => 'plan unsupported']],
        ], 200),
    ]);

    [$site] = seedMetricsSite();

    expect(fn () => (new SyncSiteCdnMetricsJob($site->id))->handle())->toThrow(\RuntimeException::class);
    expect($site->fresh()->meta['cdn']['metrics']['last_error'] ?? null)->toContain('plan unsupported');
});
