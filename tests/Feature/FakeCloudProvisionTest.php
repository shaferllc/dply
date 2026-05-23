<?php

namespace Tests\Feature\FakeCloudProvisionTest;

use App\Jobs\PollDropletIpJob;
use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'server_provision_fake.env_flag' => true,
        'server_provision_fake.allowed_environments' => ['testing'],
    ]);
});

test('fake cloud disabled when env flag off', function () {
    config(['server_provision_fake.env_flag' => false]);

    expect(FakeCloudProvision::enabled())->toBeFalse();
});

test('fake cloud disabled when environment not allowed', function () {
    config([
        'server_provision_fake.env_flag' => true,
        'server_provision_fake.allowed_environments' => ['local'],
    ]);

    expect(FakeCloudProvision::enabled())->toBeFalse();
});

test('fake cloud enabled in testing when configured', function () {
    expect(FakeCloudProvision::enabled())->toBeTrue();
});

test('provision digital ocean job skips cloud api and queues ssh ready', function () {
    Queue::fake();

    config([
        'server_provision_fake.ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNza2FzZWFlndm\n-----END OPENSSH PRIVATE KEY-----",
    ]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'token'],
    ]);

    $server = Server::factory()->digitalOcean()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_PENDING,
        'meta' => [
            'server_role' => 'application',
            'install_profile' => 'laravel_app',
            'webserver' => 'nginx',
            'php_version' => '8.3',
            'database' => 'mysql84',
            'cache_service' => 'redis',
        ],
    ]);

    (new ProvisionDigitalOceanDropletJob($server))->handle();

    $server->refresh();

    expect($server->provider_id)->toBe(FakeCloudProvision::sentinelProviderId());
    expect($server->status)->toBe(Server::STATUS_READY);
    $this->assertNotSame('', (string) $server->ssh_private_key);

    Queue::assertPushed(WaitForServerSshReadyJob::class);
});

test('poll droplet job noops for fake server without double dispatch', function () {
    Queue::fake();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'token'],
    ]);

    $server = Server::factory()->digitalOcean()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'provider_id' => FakeCloudProvision::sentinelProviderId(),
        'ip_address' => '127.0.0.1',
        'status' => Server::STATUS_READY,
        'meta' => ['server_role' => 'application'],
    ]);

    (new PollDropletIpJob($server))->handle();

    Queue::assertNotPushed(WaitForServerSshReadyJob::class);
});

test('provision hetzner job skips cloud api and queues ssh ready', function () {
    Queue::fake();

    config([
        'server_provision_fake.ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNza2FzZWFlndm\n-----END OPENSSH PRIVATE KEY-----",
    ]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'hetzner',
        'credentials' => ['api_token' => 'token'],
    ]);

    $server = Server::factory()->hetzner()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => Server::STATUS_PENDING,
        'meta' => [
            'server_role' => 'application',
            'install_profile' => 'laravel_app',
            'webserver' => 'nginx',
            'php_version' => '8.3',
            'database' => 'mysql84',
            'cache_service' => 'redis',
        ],
    ]);

    (new \App\Jobs\ProvisionHetznerServerJob($server))->handle();

    $server->refresh();

    expect($server->provider_id)->toBe(FakeCloudProvision::sentinelProviderId());
    expect($server->status)->toBe(Server::STATUS_READY);
    $this->assertNotSame('', (string) $server->ssh_private_key);

    Queue::assertPushed(WaitForServerSshReadyJob::class);
});

test('poll hetzner ip job noops for fake server without double dispatch', function () {
    Queue::fake();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'hetzner',
        'credentials' => ['api_token' => 'token'],
    ]);

    $server = Server::factory()->hetzner()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'provider_id' => FakeCloudProvision::sentinelProviderId(),
        'ip_address' => '127.0.0.1',
        'status' => Server::STATUS_READY,
        'meta' => ['server_role' => 'application'],
    ]);

    (new \App\Jobs\PollHetznerIpJob($server))->handle();

    Queue::assertNotPushed(WaitForServerSshReadyJob::class);
});
