<?php

namespace Tests\Feature\DigitalOceanCatalogTokenTest;

use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('resolve catalog uses digitalocean token when no credential selected', function () {
    config(['services.digitalocean.token' => 'dop_v1_test_catalog_token']);

    Http::fake([
        'https://api.digitalocean.com/v2/regions*' => Http::response([
            'regions' => [
                [
                    'slug' => 'nyc3',
                    'name' => 'New York 3',
                    'available' => true,
                ],
                [
                    'slug' => 'legacy',
                    'name' => 'Legacy',
                    'available' => false,
                ],
            ],
        ], 200),
        'https://api.digitalocean.com/v2/sizes*' => Http::response([
            'sizes' => [
                [
                    'slug' => 's-1vcpu-1gb',
                    'memory' => 1024,
                    'vcpus' => 1,
                    'disk' => 25,
                    'price_monthly' => 6,
                    'available' => true,
                ],
                [
                    'slug' => 'unavailable-size',
                    'memory' => 512,
                    'vcpus' => 1,
                    'disk' => 10,
                    'price_monthly' => 4,
                    'available' => false,
                ],
            ],
        ], 200),
    ]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $catalog = ResolveServerCreateCatalog::run($org, 'digitalocean', '', '');

    expect($catalog['regions'])->toHaveCount(1);
    expect($catalog['regions'][0]['value'])->toBe('nyc3');
    expect($catalog['sizes'])->toHaveCount(1);
    $this->assertStringContainsString('s-1vcpu-1gb', $catalog['sizes'][0]['label']);
    $this->assertStringContainsString('$6', $catalog['sizes'][0]['label']);
});

test('resolve catalog falls back to the platform token when the selected credential is gone', function () {
    config(['services.digitalocean.token' => 'dop_v1_test_catalog_token']);

    Http::fake([
        'https://api.digitalocean.com/v2/regions*' => Http::response([
            'regions' => [['slug' => 'sfo2', 'name' => 'San Francisco 2', 'available' => true]],
        ], 200),
        'https://api.digitalocean.com/v2/sizes*' => Http::response([
            'sizes' => [[
                'slug' => 's-1vcpu-2gb',
                'memory' => 2048,
                'vcpus' => 1,
                'disk' => 50,
                'price_monthly' => 12,
                'available' => true,
                'regions' => ['sfo2'],
            ]],
        ], 200),
    ]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $catalog = ResolveServerCreateCatalog::run($org, 'digitalocean', '01missingcredentialid000000', 'sfo2', true);

    expect($catalog['sizes'])->toHaveCount(1);
    expect($catalog['sizes'][0]['value'])->toBe('s-1vcpu-2gb');
    expect($catalog['error'] ?? null)->toBeNull();
});

test('resolve catalog reports a missing credential instead of an empty size list', function () {
    config(['services.digitalocean.token' => null]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $catalog = ResolveServerCreateCatalog::run($org, 'digitalocean', '01missingcredentialid000000', 'sfo2', true);

    expect($catalog['sizes'])->toBe([]);
    expect($catalog['error'])->toBe(__('No :provider credential or platform catalog token is available.', ['provider' => 'DigitalOcean']));
});

test('resolve catalog falls back to the platform token when the org credential fails', function () {
    config(['services.digitalocean.token' => 'dop_v1_platform']);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'dop_v1_broken'],
    ]);

    Http::fake([
        'https://api.digitalocean.com/v2/regions*' => Http::sequence()
            ->push(['message' => 'Unable to authenticate you.'], 401)
            ->push(['regions' => [['slug' => 'sfo2', 'name' => 'San Francisco 2', 'available' => true]]], 200),
        'https://api.digitalocean.com/v2/sizes*' => Http::response([
            'sizes' => [[
                'slug' => 's-1vcpu-1gb',
                'memory' => 1024,
                'vcpus' => 1,
                'disk' => 25,
                'price_monthly' => 6,
                'available' => true,
                'regions' => ['sfo2'],
            ]],
        ], 200),
    ]);

    $catalog = ResolveServerCreateCatalog::run($org, 'digitalocean', (string) $credential->id, 'sfo2', true);

    expect($catalog['sizes'])->toHaveCount(1);
    expect($catalog['sizes'][0]['value'])->toBe('s-1vcpu-1gb');
});
