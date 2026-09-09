<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Cache\CachesPageTest;

use App\Models\Organization;
use App\Models\ServiceCredential;
use App\Models\Site;
use App\Models\User;
use App\Modules\Cache\Actions\CreateManagedCache;
use App\Modules\Cache\Livewire\CacheShow;
use App\Modules\Cache\Livewire\Caches;
use App\Modules\Cache\Models\CacheSite;
use App\Modules\Cache\Models\ManagedCache;
use App\Modules\Cache\Services\PostgresCacheStore;
use App\Modules\Cache\Support\CacheItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);
usesFeatures('surface.cache');

beforeEach(function () {
    config([
        'cache_service.enabled' => true,
        'cache_service.public_url' => 'https://cache.dply.test/api/cache/v1',
        'cache_service.entitlements.defaults' => ['available' => true, 'max_caches' => 5],
        'cache_service.entitlements.plans' => [],
    ]);

    $this->user = User::factory()->create();
    $this->organization = Organization::factory()->create();
    $this->organization->users()->attach($this->user->id, ['role' => 'owner']);
    session(['current_organization_id' => $this->organization->id]);
});

function makeCache(array $attributes = []): ManagedCache
{
    return ManagedCache::query()->create(array_merge([
        'organization_id' => test()->organization->id,
        'name' => 'primary',
        'tier' => ManagedCache::TIER_SHARED,
        'status' => ManagedCache::STATUS_ACTIVE,
    ], $attributes));
}

test('the index lists the org caches', function () {
    makeCache(['name' => 'alpha']);
    makeCache(['name' => 'beta']);

    Livewire::actingAs($this->user)
        ->test(Caches::class)
        ->assertOk()
        ->assertSee('alpha')
        ->assertSee('beta');
});

test('creating a cache mints a credential and reveals the secret exactly once', function () {
    $component = Livewire::actingAs($this->user)
        ->test(Caches::class)
        ->call('startCreate')
        ->set('createName', 'orders')
        ->call('create');

    $cache = ManagedCache::query()->where('name', 'orders')->firstOrFail();

    $secret = $component->get('revealedSecret');
    expect($secret)->toBeString()->not->toBeEmpty();

    // The credential is granted on this cache and nothing else.
    $credential = $cache->credentials()->firstOrFail();
    expect($credential->allows(ServiceCredential::SERVICE_CACHE, $cache->id, ServiceCredential::SCOPE_WRITE))->toBeTrue();
    expect($credential->allows(ServiceCredential::SERVICE_QUEUE, $cache->id, ServiceCredential::SCOPE_PUSH))->toBeFalse();

    // Dismissing drops it from component state — a secret left in a Livewire
    // snapshot is a secret in the page source on every later request.
    $component->call('dismissSecret');
    expect($component->get('revealedSecret'))->toBeNull();
});

test('a duplicate name is renamed rather than refused', function () {
    makeCache(['name' => 'orders']);

    Livewire::actingAs($this->user)
        ->test(Caches::class)
        ->call('startCreate')
        ->set('createName', 'orders')
        ->call('create');

    expect(ManagedCache::query()->where('name', 'orders-2')->exists())->toBeTrue();
});

test('the plan cap stops another cache', function () {
    config(['cache_service.entitlements.defaults' => ['available' => true, 'max_caches' => 1]]);
    makeCache();

    Livewire::actingAs($this->user)
        ->test(Caches::class)
        ->call('startCreate')
        ->set('createName', 'second')
        ->call('create');

    expect(ManagedCache::query()->count())->toBe(1);
});

test('deleting requires the name typed exactly', function () {
    $cache = makeCache(['name' => 'orders']);

    $component = Livewire::actingAs($this->user)
        ->test(Caches::class)
        ->call('confirmDelete', $cache->id)
        ->set('deleteConfirmation', 'wrong')
        ->call('destroy');

    expect(ManagedCache::query()->whereKey($cache->id)->exists())->toBeTrue();

    $component->set('deleteConfirmation', 'orders')->call('destroy');

    expect(ManagedCache::query()->whereKey($cache->id)->exists())->toBeFalse();
});

test('deleting drops the items and revokes the credentials', function () {
    $result = app(CreateManagedCache::class)->handle($this->organization, 'orders');
    $cache = $result['cache'];

    app(PostgresCacheStore::class)->put($cache, new CacheItem('k', 'v', 'S', now()->addHour()->getTimestamp()));
    expect($cache->usage()->itemCount)->toBe(1);

    Livewire::actingAs($this->user)
        ->test(Caches::class)
        ->call('confirmDelete', $cache->id)
        ->set('deleteConfirmation', 'orders')
        ->call('destroy');

    expect($result['credential']->refresh()->isRevoked())->toBeTrue();
    expect(app(PostgresCacheStore::class)->usage($cache->id)->itemCount)->toBe(0);
});

test('another org cache is not reachable', function () {
    $other = Organization::factory()->create();
    $theirs = ManagedCache::query()->create([
        'organization_id' => $other->id,
        'name' => 'theirs',
        'tier' => ManagedCache::TIER_SHARED,
        'status' => ManagedCache::STATUS_ACTIVE,
    ]);

    Livewire::actingAs($this->user)
        ->test(CacheShow::class, ['managedCache' => $theirs])
        ->assertForbidden();
});

test('flushing empties the cache but keeps it', function () {
    $cache = makeCache();
    app(PostgresCacheStore::class)->put($cache, new CacheItem('k', 'v', 'S', now()->addHour()->getTimestamp()));

    Livewire::actingAs($this->user)
        ->test(CacheShow::class, ['managedCache' => $cache])
        ->call('confirmFlush')
        ->call('flush');

    expect($cache->usage()->itemCount)->toBe(0);
    expect(ManagedCache::query()->whereKey($cache->id)->exists())->toBeTrue();
});

test('attaching a site writes the env and detaching strips exactly those keys', function () {
    $cache = makeCache();
    $site = Site::factory()->create([
        'organization_id' => $this->organization->id,
        'env_file_content' => "APP_NAME=Example\nCACHE_STORE=file\n",
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(CacheShow::class, ['managedCache' => $cache])
        ->set('attachSiteId', $site->id)
        ->call('attach');

    $env = (string) $site->refresh()->env_file_content;

    expect($env)->toContain('CACHE_STORE=dynamodb');
    expect($env)->toContain('DYNAMODB_CACHE_TABLE='.$cache->id);
    expect($env)->toContain('AWS_ACCESS_KEY_ID=');
    // The pre-existing key is rewritten in place, not duplicated.
    expect(substr_count($env, 'CACHE_STORE='))->toBe(1);
    expect($env)->toContain('APP_NAME=Example');

    expect(CacheSite::query()->where('site_id', $site->id)->exists())->toBeTrue();
    expect($component->get('revealedEnvBlock'))->toContain('DYNAMODB_ENDPOINT');

    $component->call('detach', $site->id);

    $after = (string) $site->refresh()->env_file_content;

    expect($after)->not->toContain('DYNAMODB_CACHE_TABLE');
    expect($after)->not->toContain('AWS_SECRET_ACCESS_KEY');
    expect($after)->not->toContain('CACHE_STORE');
    // Untouched keys survive — detach strips exactly what attach wrote.
    expect($after)->toContain('APP_NAME=Example');
    expect(CacheSite::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

test('a site holds one cache, and re-attaching swaps rather than stacking', function () {
    $first = makeCache(['name' => 'first']);
    $second = makeCache(['name' => 'second']);
    $site = Site::factory()->create(['organization_id' => $this->organization->id]);

    Livewire::actingAs($this->user)
        ->test(CacheShow::class, ['managedCache' => $first])
        ->set('attachSiteId', $site->id)
        ->call('attach');

    Livewire::actingAs($this->user)
        ->test(CacheShow::class, ['managedCache' => $second])
        ->set('attachSiteId', $site->id)
        ->call('attach');

    $rows = CacheSite::query()->where('site_id', $site->id)->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->cache_id)->toBe($second->id);
    expect((string) $site->refresh()->env_file_content)->toContain('DYNAMODB_CACHE_TABLE='.$second->id);
});
