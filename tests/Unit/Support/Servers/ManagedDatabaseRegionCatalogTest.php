<?php

declare(strict_types=1);

use App\Enums\ServerProvider;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Support\Servers\ManagedDatabaseCatalogAuth;
use App\Support\Servers\ManagedDatabaseRegionCatalog;
use App\Support\Servers\ManagedDatabaseSizeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.digitalocean.token' => null]);
});

test('auth failure is remembered when the managed postgres catalog is empty', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/databases/options*' => Http::response([
            'message' => 'Unable to authenticate you',
        ], 401),
    ]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'dop_v1_bad'],
    ]);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => ServerProvider::DigitalOcean,
        'provider_credential_id' => $credential->id,
    ]);

    expect(ManagedDatabaseRegionCatalog::slugs($server, 'postgres'))->toBe([])
        ->and(ManagedDatabaseRegionCatalog::lastError())->toContain('Unable to authenticate you')
        ->and(ManagedDatabaseRegionCatalog::operatorMessage())->toContain('rejected this API token');
});

test('falls back to another org credential when the server token is rejected', function () {
    Http::fake(function (Request $request) {
        $auth = implode(' ', $request->header('Authorization'));
        if (str_contains($auth, 'dop_v1_bad')) {
            return Http::response(['message' => 'Unable to authenticate you'], 401);
        }

        if (str_contains($auth, 'dop_v1_good')) {
            return Http::response([
                'options' => [
                    'pg' => [
                        'regions' => ['sfo3', 'nyc3'],
                        'layouts' => [
                            ['num_nodes' => 1, 'sizes' => ['db-s-1vcpu-1gb', 'db-s-1vcpu-2gb']],
                        ],
                    ],
                ],
            ], 200);
        }

        return Http::response(['message' => 'Unable to authenticate you'], 401);
    });

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $stale = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'Stale DO',
        'credentials' => ['api_token' => 'dop_v1_bad'],
    ]);
    ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'Working DO',
        'credentials' => ['api_token' => 'dop_v1_good'],
    ]);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => ServerProvider::DigitalOcean,
        'provider_credential_id' => $stale->id,
    ]);

    expect(ManagedDatabaseRegionCatalog::slugs($server, 'postgres'))->toBe(['sfo3', 'nyc3'])
        ->and(ManagedDatabaseSizeCatalog::slugs($server, 'postgres'))->toBe(['db-s-1vcpu-1gb', 'db-s-1vcpu-2gb'])
        ->and(ManagedDatabaseRegionCatalog::lastError())->toBeNull();
});

test('uses the app DIGITALOCEAN_TOKEN before the server credential', function () {
    config(['services.digitalocean.token' => 'dop_v1_app']);

    Http::fake([
        'https://api.digitalocean.com/v2/databases/options*' => Http::response([
            'options' => [
                'pg' => [
                    'regions' => ['nyc1'],
                    'layouts' => [
                        ['num_nodes' => 1, 'sizes' => ['db-s-1vcpu-1gb']],
                    ],
                ],
            ],
        ], 200),
    ]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'dop_v1_server'],
    ]);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => ServerProvider::DigitalOcean,
        'provider_credential_id' => $credential->id,
    ]);

    expect(ManagedDatabaseRegionCatalog::slugs($server, 'postgres'))->toBe(['nyc1']);

    $first = Http::recorded()[0][0] ?? null;
    expect($first)->toBeInstanceOf(Request::class)
        ->and(implode(' ', $first->header('Authorization')))->toContain('dop_v1_app');
});

test('falls back to the platform token when org credentials fail', function () {
    config(['services.digitalocean.token' => 'dop_v1_platform']);

    Http::fake(function (Request $request) {
        $auth = implode(' ', $request->header('Authorization'));
        if (str_contains($auth, 'dop_v1_platform')) {
            return Http::response([
                'options' => [
                    'pg' => [
                        'regions' => ['nyc1'],
                        'layouts' => [
                            ['num_nodes' => 1, 'sizes' => ['db-s-1vcpu-1gb']],
                        ],
                    ],
                ],
            ], 200);
        }

        return Http::response(['message' => 'Unable to authenticate you'], 401);
    });

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'dop_v1_bad'],
    ]);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => ServerProvider::DigitalOcean,
        'provider_credential_id' => $credential->id,
    ]);

    expect(ManagedDatabaseRegionCatalog::slugs($server, 'postgres'))->toBe(['nyc1']);
});

test('provision credential skips a rejected server token and uses the working org credential', function () {
    Http::fake(function (Request $request) {
        $auth = implode(' ', $request->header('Authorization'));
        if (str_contains($auth, 'dop_v1_good')) {
            return Http::response([
                'options' => [
                    'pg' => ['regions' => ['sfo3']],
                ],
            ], 200);
        }

        return Http::response(['message' => 'Unable to authenticate you'], 401);
    });

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $stale = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'dop_v1_bad'],
    ]);
    $working = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'dop_v1_good'],
    ]);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => ServerProvider::DigitalOcean,
        'provider_credential_id' => $stale->id,
    ]);

    $resolved = ManagedDatabaseCatalogAuth::resolveCredential($server);

    expect($resolved?->id)->toBe($working->id);
});
