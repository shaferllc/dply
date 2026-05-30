<?php

declare(strict_types=1);

namespace Tests\Feature\Api\ServerSystemUserApiTest;

use App\Jobs\CreateServerSystemUserJob;
use App\Jobs\DeleteServerSystemUserJob;
use App\Jobs\SyncServerSystemUsersJob;
use App\Jobs\UpdateServerSystemUserJob;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerSystemUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * @return array{0: Server, 1: string}
 */
function readyServerAndToken(array $abilities): array
{
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->ready()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
    ]);

    ['plaintext' => $plain] = ApiToken::createToken($user, $org, 'system-users-api', null, $abilities);

    return [$server, $plain];
}

test('system users index returns stored snapshot', function (): void {
    [$server, $plain] = readyServerAndToken(['system_users.read']);

    ServerSystemUser::query()->create([
        'server_id' => $server->id,
        'username' => 'deployer',
        'uid' => 1001,
        'home' => '/home/deployer',
        'shell' => '/bin/bash',
        'groups' => ['www-data'],
        'last_seen_at' => now(),
    ]);

    $this->getJson('/api/v1/servers/'.$server->id.'/system-users', [
        'Authorization' => 'Bearer '.$plain,
    ])
        ->assertOk()
        ->assertJsonPath('data.0.username', 'deployer')
        ->assertJsonPath('data.0.uid', 1001);
});

test('system users mutations queue jobs', function (): void {
    Queue::fake();

    [$server, $plain] = readyServerAndToken([
        'system_users.read',
        'system_users.write',
        'system_users.delete',
    ]);

    $headers = ['Authorization' => 'Bearer '.$plain];

    $this->postJson('/api/v1/servers/'.$server->id.'/system-users/sync', [], $headers)
        ->assertStatus(202);
    Queue::assertPushed(SyncServerSystemUsersJob::class);

    $this->postJson('/api/v1/servers/'.$server->id.'/system-users', [
        'username' => 'deployer',
        'sudo' => false,
        'web_group' => true,
    ], $headers)->assertStatus(202);
    Queue::assertPushed(CreateServerSystemUserJob::class);

    $this->patchJson('/api/v1/servers/'.$server->id.'/system-users/deployer', [
        'sudo' => true,
    ], $headers)->assertStatus(202);
    Queue::assertPushed(UpdateServerSystemUserJob::class);

    $this->deleteJson('/api/v1/servers/'.$server->id.'/system-users/deployer', [], $headers)
        ->assertStatus(202);
    Queue::assertPushed(DeleteServerSystemUserJob::class);
});

test('system users write requires ability', function (): void {
    Queue::fake();

    [$server, $plain] = readyServerAndToken(['system_users.read']);

    $this->postJson('/api/v1/servers/'.$server->id.'/system-users', [
        'username' => 'deployer',
    ], ['Authorization' => 'Bearer '.$plain])
        ->assertForbidden();

    Queue::assertNotPushed(CreateServerSystemUserJob::class);
});
