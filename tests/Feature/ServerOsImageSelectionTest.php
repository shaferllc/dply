<?php

namespace Tests\Feature\ServerOsImageSelectionTest;

use App\Jobs\ProvisionHetznerServerJob;
use App\Livewire\Servers\Create\StepWhat;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerCreateDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function osImageWizardUser(string $provider = 'digitalocean', string $hostKind = 'vm'): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    ServerCreateDraft::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'step' => 3,
        'payload' => [
            'mode' => 'provider',
            'type' => $provider,
            'provider_host_kind' => $hostKind,
            'name' => 'test',
            'install_profile' => 'laravel_app',
            'server_role' => 'application',
            'webserver' => 'nginx',
            'php_version' => '8.3',
            'database' => 'mysql84',
            'cache_service' => 'redis',
        ],
    ]);

    return $user;
}

function osImageHetznerServer(?string $osImage): Server
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'hetzner',
        'credentials' => ['api_token' => 'hzn_test'],
    ]);

    $meta = ['server_role' => 'application'];
    if ($osImage !== null) {
        $meta['os_image'] = $osImage;
    }

    return Server::factory()->hetzner()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_PENDING,
        'meta' => $meta,
    ]);
}

beforeEach(function () {
    // Don't short-circuit into the fake-provision path; we want the real HTTP call.
    config(['server_provision_fake.env_flag' => false]);

    Http::fake([
        'https://api.hetzner.cloud/v1/ssh_keys' => Http::response([
            'ssh_key' => ['id' => 42, 'name' => 'dply-test'],
        ], 201),
        'https://api.hetzner.cloud/v1/servers' => Http::response([
            'server' => ['id' => 9001],
        ], 201),
    ]);
});

test('chosen Debian image is sent to the provider', function () {
    $server = osImageHetznerServer('debian-12');

    (new ProvisionHetznerServerJob($server))->handle();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.hetzner.cloud/v1/servers'
        && ($request->data()['image'] ?? null) === 'debian-12');
});

test('chosen older Ubuntu image is sent to the provider', function () {
    $server = osImageHetznerServer('ubuntu-22-04');

    (new ProvisionHetznerServerJob($server))->handle();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.hetzner.cloud/v1/servers'
        && ($request->data()['image'] ?? null) === 'ubuntu-22.04');
});

test('falls back to the provider default image when none is chosen', function () {
    $server = osImageHetznerServer(null);

    (new ProvisionHetznerServerJob($server))->handle();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.hetzner.cloud/v1/servers'
        && ($request->data()['image'] ?? null) === config('services.hetzner.default_image', 'ubuntu-24.04'));
});

test('falls back to the provider default when the chosen image has no slug for that provider', function () {
    // 'vultr' isn't mapped in the catalog, so a Hetzner server carrying an
    // unmapped key still provisions on the Hetzner default rather than breaking.
    $server = osImageHetznerServer('windows-2022');

    (new ProvisionHetznerServerJob($server))->handle();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.hetzner.cloud/v1/servers'
        && ($request->data()['image'] ?? null) === config('services.hetzner.default_image', 'ubuntu-24.04'));
});

test('wizard renders the OS image picker for a provider VM and defaults to Ubuntu LTS', function () {
    $user = osImageWizardUser('digitalocean', 'vm');

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->assertSet('form.os_image', 'ubuntu-24-04')
        ->assertSee('Choose an OS image')
        ->assertSee('Ubuntu 24.04 LTS')
        ->assertSee('Debian 12 (Bookworm)');
});

test('wizard persists a chosen Debian image into the draft', function () {
    $user = osImageWizardUser('digitalocean', 'vm');

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->set('form.os_image', 'debian-12')
        ->call('next')
        ->assertHasNoErrors();

    $draft = ServerCreateDraft::query()->where('user_id', $user->id)->firstOrFail();
    expect($draft->payload['os_image'])->toBe('debian-12');
});

test('wizard hides the OS image picker for Docker hosts', function () {
    // Docker hosts skip StepWhat entirely (redirected to review), so the
    // picker never renders and os_image stays empty → provider default.
    $user = osImageWizardUser('digitalocean', 'docker');

    Livewire::actingAs($user)
        ->test(StepWhat::class)
        ->assertSet('form.os_image', '');
});
