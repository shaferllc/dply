<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\BindingPlacementDetailsTest;

use App\Enums\ServerProvider;
use App\Livewire\Sites\ResourceMap;
use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function placementDetailsSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
    ]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => ServerProvider::DigitalOcean,
        'provider_credential_id' => $credential->id,
        'region' => 'sfo2',
        'ip_address' => '203.0.113.10',
        'ssh_private_key' => 'fake-key',
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    return [$user, $server, $site];
}

function fakeDoCatalog(): void
{
    Http::fake([
        'https://api.digitalocean.com/v2/regions*' => Http::response([
            'regions' => [['slug' => 'sfo2', 'name' => 'San Francisco 2', 'available' => true]],
        ], 200),
        'https://api.digitalocean.com/v2/sizes*' => Http::response([
            'sizes' => [[
                'slug' => 's-2vcpu-4gb',
                'memory' => 4096,
                'vcpus' => 2,
                'disk' => 80,
                'price_monthly' => 24,
                'available' => true,
                'regions' => ['sfo2'],
            ]],
        ], 200),
    ]);
}

test('database provision does not offer redis as an engine', function () {
    fakeDoCatalog();
    [$user, $server, $site] = placementDetailsSite();

    Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $server, 'site' => $site])
        ->call('openBindingModal', 'database', 'provision')
        ->assertSee(__('MySQL / MariaDB'))
        ->assertSee(__('PostgreSQL'))
        ->assertDontSeeHtml('value="redis"')
        ->set('bindingForm.engine', 'redis')
        ->set('bindingForm.placement', 'managed')
        ->call('saveBinding')
        ->assertDispatched('notify', message: __('Redis belongs on the Redis / Valkey resource, not Database.'), type: 'error');
});

test('redis provision waits for a placement before showing size or vendor fields', function () {
    fakeDoCatalog();
    [$user, $server, $site] = placementDetailsSite();

    $component = Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $server, 'site' => $site])
        ->call('openBindingModal', 'redis', 'provision')
        ->assertSet('bindingForm.placement', '');

    $placements = collect($component->instance()->databasePlacements());

    $managed = $placements->firstWhere('key', 'managed');
    expect($managed)->not->toBeNull()
        ->and($managed['sublabel'])->not->toContain('sfo2')
        ->and($managed['sublabel'])->not->toContain('$');

    $dockerVm = $placements->firstWhere('key', 'docker_vm');
    expect($dockerVm)->not->toBeNull()
        ->and($dockerVm['available'])->toBeTrue()
        ->and($dockerVm['note'])->toBeNull()
        ->and($dockerVm['sublabel'])->not->toContain('sfo2');

    $cacheVm = $placements->firstWhere('key', 'cache_vm');
    expect($cacheVm)->not->toBeNull()
        ->and($cacheVm['available'])->toBeTrue()
        ->and($cacheVm['engines'])->toBe(['redis'])
        ->and($cacheVm['sublabel'])->not->toContain('sfo2');

    $upstash = $placements->firstWhere('key', CloudDatabase::BACKEND_UPSTASH);
    expect($upstash)->not->toBeNull()
        ->and($upstash['available'])->toBeFalse()
        ->and($upstash['note'])->toBe(__('Coming soon'))
        ->and($upstash['account_label'])->toBe('Account email')
        ->and($upstash['account_required'])->toBeTrue();

    $component
        ->assertSee('Dedicated Redis server')
        ->assertSee('Coming soon')
        ->assertDontSee('Account email');
});

test('neon and supabase placements are coming soon by default', function () {
    fakeDoCatalog();
    [$user, $server, $site] = placementDetailsSite();

    $component = Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $server, 'site' => $site])
        ->call('openBindingModal', 'database', 'provision')
        ->set('bindingForm.engine', 'postgres');

    $placements = collect($component->instance()->databasePlacements());

    foreach ([CloudDatabase::BACKEND_NEON, CloudDatabase::BACKEND_SUPABASE] as $key) {
        $vendor = $placements->firstWhere('key', $key);
        expect($vendor)->not->toBeNull()
            ->and($vendor['available'])->toBeFalse()
            ->and($vendor['note'])->toBe(__('Coming soon'));
    }

    $component
        ->assertSee('Neon')
        ->assertSee('Supabase')
        ->assertSee(__('Coming soon'))
        ->assertDontSee('Neon API key');
});

test('neon placement unlocks when database.neon is on', function () {
    config(['features.database.neon' => true]);
    Feature::flushCache();
    fakeDoCatalog();
    [$user, $server, $site] = placementDetailsSite();

    $component = Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $server, 'site' => $site])
        ->call('openBindingModal', 'database', 'provision')
        ->set('bindingForm.engine', 'postgres');

    $neon = collect($component->instance()->databasePlacements())
        ->firstWhere('key', CloudDatabase::BACKEND_NEON);

    expect($neon)->not->toBeNull()
        ->and($neon['available'])->toBeTrue()
        ->and($neon['note'])->toBeNull();

    $component
        ->set('bindingForm.placement', CloudDatabase::BACKEND_NEON)
        ->assertSee('Neon API key');
});

test('upstash placement unlocks when database.upstash is on', function () {
    config(['features.database.upstash' => true]);
    Feature::flushCache();
    fakeDoCatalog();
    [$user, $server, $site] = placementDetailsSite();

    $component = Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $server, 'site' => $site])
        ->call('openBindingModal', 'redis', 'provision');

    $upstash = collect($component->instance()->databasePlacements())
        ->firstWhere('key', CloudDatabase::BACKEND_UPSTASH);

    expect($upstash)->not->toBeNull()
        ->and($upstash['available'])->toBeTrue()
        ->and($upstash['note'])->toBeNull();

    $component
        ->set('bindingForm.placement', CloudDatabase::BACKEND_UPSTASH)
        ->assertSee('Account email')
        ->assertSee('Upstash API key');
});

test('redis provision loads dedicated vm sizes so the picker is ready after choose', function () {
    config(['services.digitalocean.token' => 'dop_v1_test_catalog_token']);
    fakeDoCatalog();
    [$user, $server, $site] = placementDetailsSite();

    Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $server, 'site' => $site])
        ->call('openBindingModal', 'redis', 'provision')
        ->assertSet('dedicatedVmSizeError', null)
        ->assertDontSee('s-2vcpu-4gb')
        ->set('bindingForm.placement', 'cache_vm')
        ->assertSee('s-2vcpu-4gb');
});
