<?php

declare(strict_types=1);

namespace Tests\Feature\Cache\DedicatedCacheTest;

use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ServiceCredential;
use App\Models\Site;
use App\Models\User;
use App\Modules\Cache\Actions\AttachCacheToSite;
use App\Modules\Cache\Actions\DetachCacheFromSite;
use App\Modules\Cache\Models\ManagedCache;
use App\Modules\Cache\Support\CacheWiring;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['cache_service.enabled' => true]);
    $this->organization = Organization::factory()->create();
});

function redisCluster(array $attributes = []): CloudDatabase
{
    return CloudDatabase::query()->create(array_merge([
        'organization_id' => test()->organization->id,
        'name' => 'cache-primary',
        'engine' => CloudDatabase::ENGINE_REDIS,
        'version' => '8',
        'size' => 'small',
        'region' => 'nyc1',
        'backend' => CloudDatabase::BACKEND_DIGITALOCEAN,
        'status' => CloudDatabase::STATUS_ACTIVE,
        'connection' => [
            'host' => 'redis.example.com',
            'port' => 25061,
            'username' => 'default',
            'password' => 's3cret',
            'database' => '0',
            'ssl' => true,
        ],
    ], $attributes));
}

function dedicatedCache(?CloudDatabase $database = null): ManagedCache
{
    $database ??= redisCluster();

    return ManagedCache::query()->create([
        'organization_id' => test()->organization->id,
        'name' => 'primary',
        'tier' => ManagedCache::TIER_DEDICATED,
        'status' => ManagedCache::STATUS_PROVISIONING,
        'cloud_database_id' => $database->id,
    ]);
}

test('a dedicated cache takes its status from the cluster, not its own column', function () {
    $cluster = redisCluster(['status' => CloudDatabase::STATUS_PROVISIONING]);
    $cache = dedicatedCache($cluster);

    // The row says provisioning and so does the cluster.
    expect($cache->isActive())->toBeFalse();

    $cluster->forceFill(['status' => CloudDatabase::STATUS_ACTIVE])->save();

    // The row still says provisioning; the cluster is what matters, so there
    // is no second column to drift.
    expect($cache->fresh()->status)->toBe(ManagedCache::STATUS_PROVISIONING);
    expect($cache->fresh()->isActive())->toBeTrue();
    expect($cache->fresh()->effectiveStatus())->toBe(ManagedCache::STATUS_ACTIVE);
});

test('a dedicated cache is never served by the shared data plane', function () {
    $cache = dedicatedCache();

    // isReachable gates the DynamoDB-compatible endpoint. A dedicated cache is
    // dialled directly over RESP, so a request naming one must be a miss
    // rather than anything that looks like it might work.
    expect($cache->isReachable())->toBeFalse();
});

test('a dedicated cache wires Redis rather than the compatibility endpoint', function () {
    $cache = dedicatedCache();

    $env = CacheWiring::envFor($cache->fresh(), null, null);

    expect($env['CACHE_STORE'])->toBe('redis');
    expect($env)->toHaveKey('REDIS_HOST');
    expect($env['REDIS_HOST'])->toBe('redis.example.com');
    expect($env)->not->toHaveKey('DYNAMODB_ENDPOINT');
    expect($env)->not->toHaveKey('AWS_SECRET_ACCESS_KEY');

    // TLS is carried, because DO managed Redis is TLS-only and a plaintext
    // dial surfaces as an opaque 500 after deploy.
    expect($env['REDIS_SCHEME'] ?? '')->toBe('tls');
});

test('detaching strips both tiers of keys, so moving between them leaves nothing behind', function () {
    $user = User::factory()->create();
    $this->organization->users()->attach($user->id, ['role' => 'owner']);

    $shared = ManagedCache::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'shared',
        'tier' => ManagedCache::TIER_SHARED,
        'status' => ManagedCache::STATUS_ACTIVE,
    ]);
    $dedicated = dedicatedCache();

    $site = Site::factory()->create([
        'organization_id' => $this->organization->id,
        'env_file_content' => "APP_NAME=Example\n",
    ]);

    // Shared first, then swap to dedicated — the upgrade path.
    app(AttachCacheToSite::class)->handle($shared, $site);
    expect((string) $site->refresh()->env_file_content)->toContain('DYNAMODB_CACHE_TABLE');

    app(AttachCacheToSite::class)->handle($dedicated, $site);

    $env = (string) $site->refresh()->env_file_content;

    // The DynamoDB keys are gone, not merely overwritten — MANAGED_KEYS lists
    // both tiers precisely so a swap cannot leave half of the previous one.
    expect($env)->toContain('CACHE_STORE=redis');
    expect($env)->not->toContain('DYNAMODB_ENDPOINT');
    expect($env)->not->toContain('DYNAMODB_CACHE_TABLE');
    expect($env)->not->toContain('AWS_SECRET_ACCESS_KEY');
    expect($env)->toContain('APP_NAME=Example');

    app(DetachCacheFromSite::class)->handle($dedicated, $site);

    $after = (string) $site->refresh()->env_file_content;
    expect($after)->not->toContain('REDIS_HOST');
    expect($after)->not->toContain('CACHE_STORE');
    expect($after)->toContain('APP_NAME=Example');
});

test('the adoption command wraps existing redis clusters and is idempotent', function () {
    $redis = redisCluster(['name' => 'cache-orders']);
    $postgres = CloudDatabase::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'app-db',
        'engine' => CloudDatabase::ENGINE_POSTGRES,
        'version' => '16',
        'size' => 'small',
        'region' => 'nyc1',
        'backend' => CloudDatabase::BACKEND_DIGITALOCEAN,
        'status' => CloudDatabase::STATUS_ACTIVE,
        'connection' => [],
    ]);

    $this->artisan('dply:cache:adopt-redis')->assertSuccessful();

    $adopted = ManagedCache::query()->where('cloud_database_id', $redis->id)->first();

    expect($adopted)->not->toBeNull();
    expect($adopted->tier)->toBe(ManagedCache::TIER_DEDICATED);
    // The `cache-` prefix dply adds to cluster names is stripped, so a round
    // trip does not accumulate prefixes.
    expect($adopted->name)->toBe('orders');

    // Postgres is not a cache.
    expect(ManagedCache::query()->where('cloud_database_id', $postgres->id)->exists())->toBeFalse();

    // Running again changes nothing.
    $this->artisan('dply:cache:adopt-redis')->assertSuccessful();
    expect(ManagedCache::query()->count())->toBe(1);
});

test('a dry run adopts nothing', function () {
    redisCluster();

    $this->artisan('dply:cache:adopt-redis', ['--dry-run' => true])->assertSuccessful();

    expect(ManagedCache::query()->count())->toBe(0);
});

test('adoption does not grandfather, because these clusters were already billed', function () {
    // The grandfather stamp belongs to the M4 fold-in of per-function Valkey
    // clusters, which were genuinely free. Stamping these would be an
    // unrequested price cut.
    redisCluster();

    $this->artisan('dply:cache:adopt-redis')->assertSuccessful();

    expect(ManagedCache::query()->first()->grandfathered_at)->toBeNull();
});

test('the cloud database create form no longer offers redis', function () {
    $reflection = new \ReflectionClass(\App\Livewire\Cloud\DatabaseCreate::class);
    $engines = $reflection->getConstant('ENGINES');

    expect($engines)->not->toContain(CloudDatabase::ENGINE_REDIS);
    expect($engines)->toContain(CloudDatabase::ENGINE_POSTGRES);
});
