<?php

namespace Tests\Feature\DigitalOceanCatalogTokenTest;

use App\Actions\Servers\ListServerProviderCards;
use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Support\Providers\ProviderApiStatus;
use App\Support\Providers\ProviderCatalogFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

test('resolve catalog prefers the platform token when a user credential is selected', function () {
    config(['services.digitalocean.token' => 'dop_v1_platform']);

    $tokens = [];
    Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$tokens) {
        $tokens[] = $request->header('Authorization')[0] ?? '';

        if (str_contains($request->url(), '/regions')) {
            return Http::response([
                'regions' => [['slug' => 'sfo3', 'name' => 'San Francisco 3', 'available' => true]],
            ], 200);
        }

        return Http::response([
            'sizes' => [[
                'slug' => 's-1vcpu-1gb',
                'memory' => 1024,
                'vcpus' => 1,
                'disk' => 25,
                'price_monthly' => 6,
                'available' => true,
                'regions' => ['sfo3'],
            ]],
        ], 200);
    });

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'dop_v1_user'],
    ]);

    $catalog = ResolveServerCreateCatalog::run($org, 'digitalocean', (string) $credential->id, 'sfo3');

    expect($catalog['source'] ?? null)->toBe('platform')
        ->and($catalog['regions'])->not->toBeEmpty()
        ->and($catalog['sizes'])->not->toBeEmpty()
        ->and($tokens)->not->toBeEmpty();

    foreach ($tokens as $authorization) {
        expect($authorization)->toContain('dop_v1_platform')
            ->and($authorization)->not->toContain('dop_v1_user');
    }
});
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

test('resolve catalog never calls the user token when a platform token is configured', function () {
    config(['services.digitalocean.token' => 'dop_v1_platform']);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'dop_v1_user'],
    ]);

    $tokens = [];
    Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$tokens) {
        $tokens[] = $request->header('Authorization')[0] ?? '';

        return Http::response(['message' => 'Unable to authenticate you.'], 401);
    });

    $catalog = ResolveServerCreateCatalog::run($org, 'digitalocean', (string) $credential->id, 'sfo2', true);

    expect($catalog['source'] ?? null)->toBe('platform')
        ->and($catalog['regions'])->toBe([])
        ->and($tokens)->not->toBeEmpty();

    foreach ($tokens as $authorization) {
        expect($authorization)->toContain('dop_v1_platform')
            ->and($authorization)->not->toContain('dop_v1_user');
    }
});

test('resolve catalog uses the selected credential when no platform token is set', function () {
    config([
        'services.digitalocean.token' => null,
        'dply.digitalocean_token' => null,
    ]);

    $tokens = [];
    Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$tokens) {
        $tokens[] = $request->header('Authorization')[0] ?? '';

        if (str_contains($request->url(), '/regions')) {
            return Http::response([
                'regions' => [['slug' => 'nyc3', 'name' => 'New York 3', 'available' => true]],
            ], 200);
        }

        return Http::response([
            'sizes' => [[
                'slug' => 's-1vcpu-1gb',
                'memory' => 1024,
                'vcpus' => 1,
                'disk' => 25,
                'price_monthly' => 6,
                'available' => true,
                'regions' => ['nyc3'],
            ]],
        ], 200);
    });

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'dop_v1_user'],
    ]);

    $catalog = ResolveServerCreateCatalog::run($org, 'digitalocean', (string) $credential->id, 'nyc3');

    expect($catalog['source'] ?? null)->toBe('credential')
        ->and($catalog['regions'])->not->toBeEmpty()
        ->and($tokens)->not->toBeEmpty();

    foreach ($tokens as $authorization) {
        expect($authorization)->toContain('dop_v1_user');
    }
});

test('a catalog timeout pauses the provider and hides curl internals', function () {
    config(['services.digitalocean.token' => 'dop_v1_platform']);

    Http::fake(function () {
        throw new ConnectionException('cURL error 28: Operation timed out after 8002 milliseconds with 0 bytes received (see https://curl.se/libcurl/c/libcurl-errors.html) for https://api.digitalocean.com/v2/regions?per_page=200&page=1');
    });

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'dop_v1_user'],
    ]);

    $catalog = ResolveServerCreateCatalog::run($org, 'digitalocean', (string) $credential->id, '');

    expect($catalog['regions'])->toBe([])
        ->and($catalog['error'])->toBe(ProviderCatalogFailure::message('digitalocean'))
        ->and($catalog['error'])->not->toContain('curl.se')
        ->and(ProviderApiStatus::isUnreachable('digitalocean'))->toBeTrue();

    $second = ResolveServerCreateCatalog::run($org, 'digitalocean', (string) $credential->id, '');

    expect($second['provider_unreachable'] ?? false)->toBeTrue()
        ->and($second['error'])->toBe(ProviderCatalogFailure::message('digitalocean'));

    $card = collect(ListServerProviderCards::run($org))->firstWhere('id', 'digitalocean');
    expect($card['available'])->toBeFalse()
        ->and($card['unavailable_reason'])->toContain('paused');
});

test('a successful catalog is reused from cache', function () {
    config(['services.digitalocean.token' => 'dop_v1_platform']);

    $requests = 0;
    Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$requests) {
        $requests++;

        if (str_contains($request->url(), '/regions')) {
            return Http::response([
                'regions' => [['slug' => 'sfo3', 'name' => 'San Francisco 3', 'available' => true]],
            ], 200);
        }

        return Http::response([
            'sizes' => [[
                'slug' => 's-1vcpu-1gb',
                'memory' => 1024,
                'vcpus' => 1,
                'disk' => 25,
                'price_monthly' => 6,
                'available' => true,
                'regions' => ['sfo3'],
            ]],
        ], 200);
    });

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $first = ResolveServerCreateCatalog::run($org, 'digitalocean', '', 'sfo3');
    $second = ResolveServerCreateCatalog::run($org, 'digitalocean', '', 'sfo3');

    expect($first['regions'])->not->toBeEmpty()
        ->and($second['regions'])->toBe($first['regions'])
        ->and($requests)->toBe(2);
});
