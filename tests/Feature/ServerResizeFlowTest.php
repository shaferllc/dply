<?php

declare(strict_types=1);

namespace Tests\Feature\ServerResizeFlowTest;

use App\Enums\ServerProvider;
use App\Jobs\ResizeServerJob;
use App\Livewire\Servers\SettingsCard;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** Droplet 12345: s-1vcpu-2gb, 2 GB RAM, 50 GB disk, nyc1. */
function fakeResizeApi(): void
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
            'sizes' => [
                [
                    'slug' => 's-2vcpu-4gb', 'vcpus' => 2, 'memory' => 4096, 'disk' => 50,
                    'regions' => ['nyc1'], 'available' => true, 'price_monthly' => 24.0,
                ],
                [
                    'slug' => 's-4vcpu-8gb', 'vcpus' => 4, 'memory' => 8192, 'disk' => 160,
                    'regions' => ['nyc1'], 'available' => true, 'price_monthly' => 48.0,
                ],
            ],
            'links' => [],
            'meta' => ['total' => 2],
        ]),
    ]);
}

function ownerWithDroplet(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'dop_v1_ok'],
    ]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'provider' => ServerProvider::DigitalOcean,
        'provider_credential_id' => $credential->id,
        'provider_id' => '12345',
        'size' => 's-1vcpu-2gb',
        'region' => 'nyc1',
    ]);

    return [$user, $server];
}

test('opening the modal loads the legal sizes for the droplet', function (): void {
    fakeResizeApi();
    [$user, $server] = ownerWithDroplet();

    Livewire::actingAs($user)
        ->test(SettingsCard::class, ['server' => $server, 'section' => 'connection'])
        ->call('openResizeModal')
        ->assertSet('showResizeModal', true)
        ->assertSet('resizeError', null)
        ->assertSeeText('s-2vcpu-4gb')
        ->assertSeeText('s-4vcpu-8gb');
});

test('a same-disk target queues a reversible cpu/ram resize', function (): void {
    Queue::fake();
    fakeResizeApi();
    [$user, $server] = ownerWithDroplet();

    Livewire::actingAs($user)
        ->test(SettingsCard::class, ['server' => $server, 'section' => 'connection'])
        ->call('openResizeModal')
        ->set('resizeTarget', 's-2vcpu-4gb')
        ->call('resizeServer')
        ->assertSet('showResizeModal', false);

    Queue::assertPushed(
        ResizeServerJob::class,
        fn (ResizeServerJob $job): bool => $job->targetSize === 's-2vcpu-4gb' && $job->growDisk === false,
    );
});

test('a larger-disk target queues the permanent disk-growing resize', function (): void {
    Queue::fake();
    fakeResizeApi();
    [$user, $server] = ownerWithDroplet();

    Livewire::actingAs($user)
        ->test(SettingsCard::class, ['server' => $server, 'section' => 'connection'])
        ->call('openResizeModal')
        ->set('resizeTarget', 's-4vcpu-8gb')
        ->call('resizeServer');

    Queue::assertPushed(
        ResizeServerJob::class,
        fn (ResizeServerJob $job): bool => $job->targetSize === 's-4vcpu-8gb' && $job->growDisk === true,
    );
});

test('a size that is not a legal target is refused and queues nothing', function (): void {
    Queue::fake();
    fakeResizeApi();
    [$user, $server] = ownerWithDroplet();

    Livewire::actingAs($user)
        ->test(SettingsCard::class, ['server' => $server, 'section' => 'connection'])
        ->call('openResizeModal')
        ->set('resizeTarget', 's-1vcpu-1gb')
        ->call('resizeServer')
        ->assertSet('showResizeModal', true);

    Queue::assertNotPushed(ResizeServerJob::class);
});

test('a provider error surfaces in the modal instead of throwing', function (): void {
    Http::fake([
        'https://api.digitalocean.com/v2/droplets/12345' => Http::response(['message' => 'Unable to authenticate you'], 401),
    ]);
    [$user, $server] = ownerWithDroplet();

    Livewire::actingAs($user)
        ->test(SettingsCard::class, ['server' => $server, 'section' => 'connection'])
        ->call('openResizeModal')
        ->assertSet('showResizeModal', true)
        ->assertSet('resizeCatalog', null)
        ->assertSetStrict('resizeError', fn ($v) => $v !== null);
});

test('the resize control is hidden for a non-digitalocean server', function (): void {
    [$user, $server] = ownerWithDroplet();
    $server->update(['provider' => ServerProvider::Hetzner]);

    Livewire::actingAs($user)
        ->test(SettingsCard::class, ['server' => $server->fresh(), 'section' => 'connection'])
        ->assertDontSeeText('Power off and resize');
});
