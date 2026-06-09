<?php

declare(strict_types=1);

namespace Tests\Feature\ManagedVultrProvisionTest;

use App\Jobs\PollVultrIpJob;
use App\Jobs\ProvisionVultrServerJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function managedVultrServer(): Server
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    return Server::factory()->vultr()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'hosting_backend' => Server::HOSTING_BACKEND_DPLY,
        'provider_credential_id' => null,
        'status' => Server::STATUS_PENDING,
        'meta' => ['server_role' => 'application'],
    ]);
}

test('managed vultr provision uses the platform token with no customer credential', function () {
    config([
        'managed_servers.provider' => 'vultr',
        'managed_servers.vultr.api_token' => 'platform-vultr-tok',
        'server_provision_fake.env_flag' => false,
    ]);

    Queue::fake();
    Http::fake([
        'https://api.vultr.com/v2/ssh-keys' => Http::response(['ssh_key' => ['id' => 'ssh-42']], 201),
        'https://api.vultr.com/v2/instances' => Http::response(['instance' => ['id' => 'vps-9001']], 201),
    ]);

    $server = managedVultrServer();
    expect($server->usesManagedHosting())->toBeTrue();

    (new ProvisionVultrServerJob($server))->handle();
    $server->refresh();

    expect($server->provider_id)->toBe('vps-9001')
        ->and($server->status)->toBe(Server::STATUS_PROVISIONING)
        ->and($server->ssh_private_key)->not->toBeNull();

    // Every call went out under dply's platform token, not a customer credential.
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer platform-vultr-tok'));

    Queue::assertPushed(PollVultrIpJob::class);
});

test('managed vultr provision fails clearly when the platform token is missing', function () {
    config([
        'managed_servers.provider' => 'vultr',
        'managed_servers.vultr.api_token' => '',
        'server_provision_fake.env_flag' => false,
    ]);

    $server = managedVultrServer();

    (new ProvisionVultrServerJob($server))->handle();
    $server->refresh();

    expect($server->status)->toBe(Server::STATUS_ERROR)
        ->and(data_get($server->meta, 'provision_error.message'))->toContain('not configured');
});
