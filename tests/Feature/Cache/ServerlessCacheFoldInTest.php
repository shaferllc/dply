<?php

declare(strict_types=1);

namespace Tests\Feature\Cache\ServerlessCacheFoldInTest;

use App\Models\Organization;
use App\Models\Site;
use App\Modules\Cache\Models\CacheSite;
use App\Modules\Cache\Models\ManagedCache;
use App\Modules\Cache\Services\ServerlessCacheProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache_service.enabled' => true,
        'cache_service.public_url' => 'https://cache.dply.test/api/cache/v1',
        'cache_service.entitlements.defaults' => ['available' => true, 'max_caches' => 5],
        'cache_service.entitlements.plans' => [],
    ]);

    $this->organization = Organization::factory()->create();
});

function fnSite(array $env = []): Site
{
    $content = '';
    foreach ($env as $k => $v) {
        $content .= $k.'='.$v."\n";
    }

    return Site::factory()->create([
        'organization_id' => test()->organization->id,
        'serverless_backend' => Site::SERVERLESS_BACKEND_DPLY,
        'env_file_content' => $content,
    ]);
}

test('a function with no cache store gets one wired automatically', function () {
    // The whole point of the free tier: the customer should not have to know
    // the per-container failure mode exists in order not to have it.
    $site = fnSite(['APP_NAME' => 'Example']);

    $wired = app(ServerlessCacheProvisioner::class)->wire($site, ['APP_NAME' => 'Example']);

    expect($wired)->not->toBe([]);
    expect((string) $site->refresh()->env_file_content)->toContain('CACHE_STORE=dynamodb');
    expect(CacheSite::query()->where('site_id', $site->id)->exists())->toBeTrue();
});

test('array and file stores are treated as broken, because on a function they are', function (string $store) {
    $site = fnSite(['CACHE_STORE' => $store]);

    $wired = app(ServerlessCacheProvisioner::class)->wire($site, ['CACHE_STORE' => $store]);

    expect($wired)->not->toBe([]);
})->with(['array', 'file']);

test('a deliberate cache store is never silently repointed', function (string $store) {
    // An operator who chose redis has made a choice. Overriding it would be a
    // worse failure than the one being fixed.
    $site = fnSite(['CACHE_STORE' => $store]);

    $wired = app(ServerlessCacheProvisioner::class)->wire($site, ['CACHE_STORE' => $store]);

    expect($wired)->toBe([]);
    expect(CacheSite::query()->where('site_id', $site->id)->exists())->toBeFalse();
})->with(['redis', 'memcached', 'database']);

test('a second deploy does not rotate the credential', function () {
    $site = fnSite();
    $provisioner = app(ServerlessCacheProvisioner::class);

    $provisioner->wire($site, []);
    $first = (string) $site->refresh()->env_file_content;

    // Re-attaching on every deploy would churn the customer's environment and
    // mint a credential each time.
    $again = $provisioner->wire($site->refresh(), ['CACHE_STORE' => 'dynamodb']);

    expect($again)->toBe([]);
    expect((string) $site->refresh()->env_file_content)->toBe($first);
});

test('every function in an org shares one cache rather than one each', function () {
    $a = fnSite();
    $b = fnSite();

    $provisioner = app(ServerlessCacheProvisioner::class);
    $provisioner->wire($a, []);
    $provisioner->wire($b, []);

    // One cluster's worth of allowance, not one per function.
    expect(ManagedCache::query()->where('organization_id', $this->organization->id)->count())->toBe(1);
    expect(CacheSite::query()->count())->toBe(2);
});

test('the kill switch stops auto-wiring', function () {
    config(['cache_service.enabled' => false]);
    $site = fnSite();

    expect(app(ServerlessCacheProvisioner::class)->wire($site, []))->toBe([]);
});

test('a failure never takes the deploy down', function () {
    // A plan that allows no caches makes CreateManagedCache throw. The
    // provisioner must swallow it and return empty: a cache is an improvement
    // to a deploy, not a precondition for one, and failing the deploy would
    // trade a silent bug for a loud outage.
    config(['cache_service.entitlements.defaults' => ['available' => true, 'max_caches' => 0]]);

    $site = fnSite();

    expect(app(ServerlessCacheProvisioner::class)->wire($site, []))->toBe([]);
    expect(CacheSite::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

test('the doctor reports the store isolation condition rather than hiding it', function () {
    // Sharing the control plane's database is a legitimate way to run a small
    // install, so this must surface rather than fail closed — but it is
    // reported as a failed check, because the surrounding code is written as
    // though it is not true.
    $this->artisan('dply:cache:doctor --json')
        ->assertFailed()
        ->expectsOutputToContain('store_isolation');
});

test('the doctor confirms the item store is unlogged', function () {
    // UNLOGGED is not cosmetic — it is the reason a cache's write volume does
    // not put WAL pressure on whatever database this resolves to.
    $this->artisan('dply:cache:doctor --json')
        ->expectsOutputToContain('UNLOGGED');
});
