<?php

namespace Tests\Feature\ProviderResourceTaggingTest;

use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Jobs\ProvisionHetznerServerJob;
use App\Jobs\ProvisionLinodeServerJob;
use App\Jobs\ProvisionUpCloudServerJob;
use App\Jobs\ProvisionVultrServerJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Support\Servers\ProviderResourceTags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    // Real HTTP path — the fake-cloud shortcut never talks to a provider.
    config(['server_provision_fake.env_flag' => false]);
});

/**
 * A pending server of $provider owned by a fresh user/org, with a matching credential.
 */
function taggableServer(string $provider, string $state, array $credentials): Server
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => $provider,
        'credentials' => $credentials,
    ]);

    return Server::factory()->{$state}()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_PENDING,
        'meta' => ['server_role' => 'application'],
    ]);
}

test('digitalocean droplets carry the dply tags alongside user tags', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/account/keys' => Http::response(['ssh_key' => ['id' => 7]], 201),
        'https://api.digitalocean.com/v2/droplets' => Http::response(['droplet' => ['id' => 9001]], 202),
    ]);

    $server = taggableServer('digitalocean', 'digitalOcean', ['api_token' => 'do_test']);
    $server->update(['meta' => ['server_role' => 'application', 'digitalocean' => ['tags' => ['prod']]]]);

    (new ProvisionDigitalOceanDropletJob($server))->handle();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.digitalocean.com/v2/droplets'
        && ($request->data()['tags'] ?? []) === ['dply', 'dply-'.$server->id, 'prod']);
});

test('hetzner servers carry the dply labels', function () {
    Http::fake([
        'https://api.hetzner.cloud/v1/ssh_keys' => Http::response(['ssh_key' => ['id' => 42]], 201),
        'https://api.hetzner.cloud/v1/firewalls*' => Http::response(['firewalls' => []], 200),
        'https://api.hetzner.cloud/v1/servers' => Http::response(['server' => ['id' => 9001]], 201),
    ]);

    $server = taggableServer('hetzner', 'hetzner', ['api_token' => 'hzn_test']);

    (new ProvisionHetznerServerJob($server))->handle();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.hetzner.cloud/v1/servers'
        && ($request->data()['labels'] ?? []) === [
            'managed-by' => 'dply',
            'dply-server-id' => $server->id,
        ]);
});

test('vultr instances carry the dply tags', function () {
    Http::fake([
        'https://api.vultr.com/v2/ssh-keys' => Http::response(['ssh_key' => ['id' => 'key-1']], 201),
        'https://api.vultr.com/v2/instances' => Http::response(['instance' => ['id' => 'inst-1']], 202),
    ]);

    $server = taggableServer('vultr', 'vultr', ['api_token' => 'vultr_test']);

    (new ProvisionVultrServerJob($server))->handle();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.vultr.com/v2/instances'
        && ($request->data()['tags'] ?? []) === ['dply', 'dply-'.$server->id]);
});

test('linode instances carry the dply tags', function () {
    Http::fake([
        'https://api.linode.com/v4/linode/instances' => Http::response(['id' => 9001], 200),
    ]);

    $server = taggableServer('linode', 'linode', ['api_token' => 'lin_test']);

    (new ProvisionLinodeServerJob($server))->handle();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.linode.com/v4/linode/instances'
        && ($request->data()['tags'] ?? []) === ['dply', 'dply-'.$server->id]);
});

test('upcloud servers carry the dply labels', function () {
    Http::fake([
        'https://api.upcloud.com/1.3/server' => Http::response(['server' => ['uuid' => 'uuid-1']], 202),
    ]);

    $server = taggableServer('upcloud', 'upcloud', ['api_username' => 'u', 'api_password' => 'p']);

    (new ProvisionUpCloudServerJob($server))->handle();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.upcloud.com/1.3/server'
        && ($request->data()['server']['labels']['label'] ?? []) === [
            ['key' => 'managed-by', 'value' => 'dply'],
            ['key' => 'dply-server-id', 'value' => $server->id],
        ]);
});

test('the identity tag points back at the exact server row', function () {
    $server = Server::factory()->digitalOcean()->create();

    expect(ProviderResourceTags::forServer($server))->toBe('dply-'.$server->id)
        ->and(ProviderResourceTags::belongsToServer($server, ProviderResourceTags::tags($server)))->toBeTrue();
});
