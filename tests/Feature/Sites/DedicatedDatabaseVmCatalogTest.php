<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\DedicatedDatabaseVmCatalogTest;

use App\Enums\ServerProvider;
use App\Livewire\Sites\ResourceMap;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function dedicatedVmCatalogSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $foreignCredential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => Organization::factory(),
        'provider' => 'digitalocean',
    ]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => ServerProvider::DigitalOcean,
        'provider_credential_id' => $foreignCredential->id,
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

test('dedicated docker card uses the platform catalog token when the server credential is gone', function () {
    config(['services.digitalocean.token' => 'dop_v1_test_catalog_token']);

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

    [$user, $server, $site] = dedicatedVmCatalogSite();

    Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $server, 'site' => $site])
        ->call('openBindingModal', 'database', 'provision')
        ->assertSet('dedicatedVmSizeError', null)
        ->set('bindingForm.placement', 'docker_vm')
        ->assertSee('s-2vcpu-4gb')
        ->assertDontSee(__('No sizes available for this provider/region.'));
});

test('dedicated docker card shows the catalog failure when sizes cannot load', function () {
    config(['services.digitalocean.token' => 'dop_v1_fail_catalog_token']);

    Http::fake([
        'https://api.digitalocean.com/v2/*' => Http::response([
            'message' => 'Unable to authenticate you.',
        ], 401),
    ]);

    [$user, $server, $site] = dedicatedVmCatalogSite();

    Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $server, 'site' => $site])
        ->call('openBindingModal', 'database', 'provision')
        ->assertDontSee('Unable to authenticate you.')
        ->set('bindingForm.placement', 'docker_vm')
        ->assertSee('Unable to authenticate you.')
        ->assertDontSee(__('No sizes available for this provider/region.'));
});
