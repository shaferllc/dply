<?php

declare(strict_types=1);

use App\Enums\ServerProvider;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\Resize\HetznerResizeDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Server-type catalogs are cached per token; a stale entry would leak
    // between these cases.
    Cache::flush();
});

/** Current server: cx22, 2 cores, 4 GB, 40 GB disk, in nbg1. */
function fakeHetznerResizeApi(array $serverTypes): void
{
    Http::fake([
        'https://api.hetzner.cloud/v1/servers/777' => Http::response([
            'server' => [
                'id' => 777,
                'server_type' => ['name' => 'cx22', 'cores' => 2, 'memory' => 4, 'disk' => 40],
                'datacenter' => ['location' => ['name' => 'nbg1']],
            ],
        ]),
        'https://api.hetzner.cloud/v1/server_types*' => Http::response(['server_types' => $serverTypes]),
    ]);
}

function hetznerType(string $name, int $cores, float $memoryGb, int $disk, string $location = 'nbg1', bool $deprecated = false): array
{
    return [
        'name' => $name,
        'cores' => $cores,
        'memory' => $memoryGb,
        'disk' => $disk,
        'deprecated' => $deprecated,
        'prices' => [
            ['location' => $location, 'price_monthly' => ['gross' => '8.2100000000', 'net' => '6.90']],
        ],
    ];
}

function hetznerServer(): Server
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'hetzner',
        'credentials' => ['api_token' => 'hz_ok'],
    ]);

    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => ServerProvider::Hetzner,
        'provider_credential_id' => $credential->id,
        'provider_id' => '777',
        'size' => 'cx22',
        'region' => 'nbg1',
    ]);
}

test('hetzner memory is converted from GB to MB', function () {
    fakeHetznerResizeApi([hetznerType('cx32', 4, 8, 80)]);

    $catalog = app(HetznerResizeDriver::class)->catalog(hetznerServer());

    expect($catalog['current']['memory_mb'])->toBe(4096)
        ->and($catalog['options'][0]['memory_mb'])->toBe(8192);
});

test('a smaller disk is not a legal hetzner target', function () {
    fakeHetznerResizeApi([
        hetznerType('cx11', 1, 2, 20),  // smaller disk
        hetznerType('cx32', 4, 8, 80),  // bigger, legal
    ]);

    $catalog = app(HetznerResizeDriver::class)->catalog(hetznerServer());

    expect(collect($catalog['options'])->pluck('slug')->all())->toBe(['cx32']);
});

test('a type not sold in the server location is dropped', function () {
    fakeHetznerResizeApi([
        hetznerType('cx32', 4, 8, 80, 'hel1'),   // priced only in Helsinki
        hetznerType('cx42', 8, 16, 160, 'nbg1'), // priced where the server is
    ]);

    $catalog = app(HetznerResizeDriver::class)->catalog(hetznerServer());

    expect(collect($catalog['options'])->pluck('slug')->all())->toBe(['cx42']);
});

test('deprecated hetzner types are not offered', function () {
    fakeHetznerResizeApi([
        hetznerType('cx31', 2, 8, 80, 'nbg1', deprecated: true),
        hetznerType('cx32', 4, 8, 80),
    ]);

    $catalog = app(HetznerResizeDriver::class)->catalog(hetznerServer());

    expect(collect($catalog['options'])->pluck('slug')->all())->toBe(['cx32']);
});

test('a bigger hetzner disk is flagged as a permanent upgrade', function () {
    fakeHetznerResizeApi([
        hetznerType('cx22-alt', 3, 6, 40),  // same disk
        hetznerType('cx32', 4, 8, 80),      // bigger disk
    ]);

    $options = collect(app(HetznerResizeDriver::class)->catalog(hetznerServer())['options'])->keyBy('slug');

    expect($options['cx22-alt']['grows_disk'])->toBeFalse()
        ->and($options['cx32']['grows_disk'])->toBeTrue();
});

test('hetzner resize powers the machine down', function () {
    expect(app(HetznerResizeDriver::class)->requiresPowerCycle())->toBeTrue();
});
