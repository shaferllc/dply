<?php

declare(strict_types=1);

use App\Enums\ServerProvider;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerResizeOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Current droplet: s-1vcpu-2gb, 2 GB RAM, 50 GB disk, in nyc1.
 */
function fakeDoResizeApi(array $sizes): void
{
    Http::fake([
        'https://api.digitalocean.com/v2/droplets/12345' => Http::response([
            'droplet' => [
                'id' => 12345,
                'size_slug' => 's-1vcpu-2gb',
                'vcpus' => 1,
                'memory' => 2048,
                'disk' => 50,
                'region' => ['slug' => 'nyc1'],
            ],
        ]),
        'https://api.digitalocean.com/v2/sizes*' => Http::response([
            'sizes' => $sizes,
            'links' => [],
            'meta' => ['total' => count($sizes)],
        ]),
    ]);
}

function doSize(string $slug, int $vcpus, int $memory, int $disk, array $regions = ['nyc1'], bool $available = true): array
{
    return [
        'slug' => $slug,
        'vcpus' => $vcpus,
        'memory' => $memory,
        'disk' => $disk,
        'regions' => $regions,
        'available' => $available,
        'price_monthly' => 12.0,
    ];
}

function resizableServer(): Server
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'dop_v1_ok'],
    ]);

    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => ServerProvider::DigitalOcean,
        'provider_credential_id' => $credential->id,
        'provider_id' => '12345',
        'size' => 's-1vcpu-2gb',
        'region' => 'nyc1',
    ]);
}

test('a smaller disk is never offered, because a droplet disk cannot shrink', function () {
    fakeDoResizeApi([
        doSize('s-1vcpu-1gb', 1, 1024, 25),   // smaller disk — illegal
        doSize('s-2vcpu-4gb', 2, 4096, 50),   // same disk — legal
    ]);

    $result = app(ServerResizeOptions::class)->forServer(resizableServer());

    expect(collect($result['options'])->pluck('slug')->all())->toBe(['s-2vcpu-4gb']);
});

test('same disk is a reversible cpu/ram resize, larger disk is flagged permanent', function () {
    fakeDoResizeApi([
        doSize('s-2vcpu-4gb', 2, 4096, 50),
        doSize('s-4vcpu-8gb', 4, 8192, 160),
    ]);

    $options = collect(app(ServerResizeOptions::class)->forServer(resizableServer())['options'])
        ->keyBy('slug');

    expect($options['s-2vcpu-4gb']['grows_disk'])->toBeFalse()
        ->and($options['s-4vcpu-8gb']['grows_disk'])->toBeTrue();
});

test('sizes outside the droplet region or marked unavailable are dropped', function () {
    fakeDoResizeApi([
        doSize('s-2vcpu-4gb', 2, 4096, 50, ['sfo3']),          // wrong region
        doSize('s-8vcpu-16gb', 8, 16384, 50, ['nyc1'], false), // sold out
        doSize('s-2vcpu-2gb', 2, 2048, 50),                    // keeper
    ]);

    $result = app(ServerResizeOptions::class)->forServer(resizableServer());

    expect(collect($result['options'])->pluck('slug')->all())->toBe(['s-2vcpu-2gb']);
});

test('the current size is not offered as a target', function () {
    fakeDoResizeApi([
        doSize('s-1vcpu-2gb', 1, 2048, 50),
        doSize('s-2vcpu-4gb', 2, 4096, 50),
    ]);

    $result = app(ServerResizeOptions::class)->forServer(resizableServer());

    expect(collect($result['options'])->pluck('slug')->all())->toBe(['s-2vcpu-4gb']);
});

test('resolveTarget refuses a slug that is not a legal target', function () {
    fakeDoResizeApi([doSize('s-2vcpu-4gb', 2, 4096, 50)]);

    $server = resizableServer();
    $options = app(ServerResizeOptions::class);

    expect($options->resolveTarget($server, 's-2vcpu-4gb')['slug'])->toBe('s-2vcpu-4gb');

    $options->resolveTarget($server, 's-96vcpu-999gb');
})->throws(RuntimeException::class);

test('non-digitalocean servers are not resizable from dply', function () {
    $server = Server::factory()->ready()->create([
        'provider' => ServerProvider::Hetzner,
        'provider_id' => '999',
    ]);

    expect(app(ServerResizeOptions::class)->supports($server))->toBeFalse();
});
