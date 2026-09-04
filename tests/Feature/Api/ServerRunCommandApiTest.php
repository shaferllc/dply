<?php

namespace Tests\Feature\Api\ServerRunCommandApiTest;

use App\Jobs\RunServerCommandJob;
use App\Models\ApiToken;
use App\Models\ConsoleAction;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * @return array{0: Server, 1: string, 2: User}
 */
function commandServer(array $abilities = ['servers.read', 'commands.run']): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->ready()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ]);

    ['plaintext' => $plaintext] = ApiToken::createToken($user, $organization, 'test', null, $abilities);

    return [$server, $plaintext, $user];
}

it('queues the command instead of opening SSH in the request', function () {
    Queue::fake();
    [$server, $token] = commandServer();

    $response = $this->withToken($token)
        ->postJson("/api/v1/servers/{$server->id}/run-command", ['command' => 'uptime', 'wait_seconds' => 0])
        ->assertOk()
        ->assertJsonPath('status', ConsoleAction::STATUS_QUEUED);

    Queue::assertPushed(RunServerCommandJob::class, function (RunServerCommandJob $job) use ($server, $response) {
        return $job->serverId === $server->id
            && $job->command === 'uptime'
            && $job->consoleActionId === $response->json('run_id');
    });
});

it('records who ran the command', function () {
    Queue::fake();
    [$server, $token, $user] = commandServer();

    $this->withToken($token)
        ->postJson("/api/v1/servers/{$server->id}/run-command", ['command' => 'uptime', 'wait_seconds' => 0])
        ->assertOk();

    // The old endpoint recorded nothing at all: no actor, no run, no exit code.
    $action = ConsoleAction::query()->firstOrFail();
    expect($action->user_id)->toBe($user->id)
        ->and($action->subject_id)->toBe($server->id)
        ->and($action->label)->toBe('uptime');
});

it('polls a run back through the server that owns it', function () {
    Queue::fake();
    [$server, $token] = commandServer();
    [$otherServer] = commandServer();

    $mine = ConsoleAction::query()->create([
        'subject_type' => $server->getMorphClass(),
        'subject_id' => $server->id,
        'kind' => 'server:run-command',
        'status' => ConsoleAction::STATUS_COMPLETED,
        'output' => ['v' => 1, 'lines' => [['t' => 1, 'level' => 'info', 'source' => 'command', 'line' => 'up 3 days']]],
    ]);
    $foreign = ConsoleAction::query()->create([
        'subject_type' => $otherServer->getMorphClass(),
        'subject_id' => $otherServer->id,
        'kind' => 'server:run-command',
        'status' => ConsoleAction::STATUS_COMPLETED,
    ]);

    $this->withToken($token)
        ->getJson("/api/v1/servers/{$server->id}/commands/{$mine->id}")
        ->assertOk()
        ->assertJsonPath('run_id', $mine->id)
        ->assertJsonPath('output', 'up 3 days')
        ->assertJsonPath('exit_code', 0);

    $this->withToken($token)
        ->getJson("/api/v1/servers/{$server->id}/commands/{$foreign->id}")
        ->assertNotFound();
});

it('reports a non-zero exit without calling it a transport failure', function () {
    Queue::fake();
    [$server, $token] = commandServer();

    $action = ConsoleAction::query()->create([
        'subject_type' => $server->getMorphClass(),
        'subject_id' => $server->id,
        'kind' => 'server:run-command',
        'status' => ConsoleAction::STATUS_COMPLETED,
        'error' => 'exit 2',
    ]);

    // grep finding nothing is data, not a failed run.
    $this->withToken($token)
        ->getJson("/api/v1/servers/{$server->id}/commands/{$action->id}")
        ->assertOk()
        ->assertJsonPath('status', ConsoleAction::STATUS_COMPLETED)
        ->assertJsonPath('exit_code', 2)
        ->assertJsonPath('error', null);
});

it('never returns the raw exception text of a failed run', function () {
    Queue::fake();
    [$server, $token] = commandServer();

    $action = ConsoleAction::query()->create([
        'subject_type' => $server->getMorphClass(),
        'subject_id' => $server->id,
        'kind' => 'server:run-command',
        'status' => ConsoleAction::STATUS_FAILED,
        'error' => 'SSH2: Connection to 10.0.0.5 failed: key /root/.ssh/id_rsa rejected',
    ]);

    $response = $this->withToken($token)
        ->getJson("/api/v1/servers/{$server->id}/commands/{$action->id}")
        ->assertOk()
        ->assertJsonPath('status', ConsoleAction::STATUS_FAILED);

    expect($response->json('error'))->not->toContain('10.0.0.5')
        ->and($response->json('error'))->not->toContain('id_rsa');
});

it('refuses a server in another organization', function () {
    Queue::fake();
    [$server] = commandServer();
    [, $otherToken] = commandServer();

    $this->withToken($otherToken)
        ->postJson("/api/v1/servers/{$server->id}/run-command", ['command' => 'uptime'])
        ->assertForbidden();

    Queue::assertNotPushed(RunServerCommandJob::class);
});

it('requires the commands.run ability', function () {
    Queue::fake();
    [$server, $token] = commandServer(['servers.read']);

    $this->withToken($token)
        ->postJson("/api/v1/servers/{$server->id}/run-command", ['command' => 'uptime'])
        ->assertForbidden();

    Queue::assertNotPushed(RunServerCommandJob::class);
});
