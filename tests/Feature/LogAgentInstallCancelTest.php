<?php

namespace Tests\Feature\LogAgentInstallCancelTest;

use App\Actions\Servers\ManageServerLogShipping;
use App\Exceptions\LogShippingException;
use App\Livewire\Servers\WorkspaceLogs;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerLogAgent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function userWithOrganization(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

function serverWithAgent(string $status): array
{
    $user = userWithOrganization();
    $org = $user->currentOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $agent = ServerLogAgent::query()->create([
        'server_id' => $server->id,
        'status' => $status,
        'enabled_sources' => ServerLogAgent::configuredSourceDefaults(),
    ]);

    return [$user, $server, $agent];
}

test('canceling a stuck install unblocks the panel', function () {
    [$user, $server, $agent] = serverWithAgent(ServerLogAgent::STATUS_INSTALLING);

    Livewire::actingAs($user)
        ->test(WorkspaceLogs::class, ['server' => $server])
        ->call('cancelLogShipping');

    $agent->refresh();

    // `failed` is the state the UI already renders "Retry install" for, and a
    // cancelled install genuinely is one that did not complete.
    expect($agent->status)->toBe(ServerLogAgent::STATUS_FAILED)
        ->and($agent->isBusy())->toBeFalse()
        ->and($agent->error_message)->toContain('canceled');
});

test('canceling a removal explains the partial state', function () {
    [$user, $server, $agent] = serverWithAgent(ServerLogAgent::STATUS_UNINSTALLING);

    Livewire::actingAs($user)
        ->test(WorkspaceLogs::class, ['server' => $server])
        ->call('cancelLogShipping');

    expect($agent->refresh()->error_message)->toContain('partially removed');
});

test('cancel is refused when nothing is running', function () {
    [, $server] = serverWithAgent(ServerLogAgent::STATUS_RUNNING);

    expect(fn () => app(ManageServerLogShipping::class)->cancel($server))
        ->toThrow(LogShippingException::class);
});

test('cancel is refused when there is no agent at all', function () {
    $user = userWithOrganization();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
    ]);

    expect(fn () => app(ManageServerLogShipping::class)->cancel($server))
        ->toThrow(LogShippingException::class);
});

test('a canceled agent can be re-enabled', function () {
    [, $server, $agent] = serverWithAgent(ServerLogAgent::STATUS_INSTALLING);

    app(ManageServerLogShipping::class)->cancel($server);
    $server->load('logAgent');

    // The whole point of landing on `failed`: retry has to be available, and the
    // install is idempotent so re-running it is safe.
    app(ManageServerLogShipping::class)->enable($server);

    expect($agent->refresh()->status)->toBe(ServerLogAgent::STATUS_INSTALLING)
        ->and($agent->error_message)->toBeNull();
});
