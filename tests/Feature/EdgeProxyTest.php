<?php

namespace Tests\Feature\EdgeProxyTest;

use App\Jobs\AddEdgeProxyJob;
use App\Jobs\RemoveEdgeProxyJob;
use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\ConsoleAction;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);

uses(WithFeatures::class);

function makeUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $org->id]);
    session(['current_organization_id' => $org->id]);

    return $user->fresh();
}

function makeServer(User $user, array $metaOverrides = []): Server
{
    return Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $user->currentOrganization()->id,
        'meta' => array_merge([
            'webserver' => 'nginx',
            'manage_units' => [['unit' => 'nginx', 'active_state' => 'active']],
        ], $metaOverrides),
    ]);
}

test('server edge proxy helpers', function () {
    $user = makeUser();
    $server = makeServer($user);
    expect($server->edgeProxy())->toBeNull();
    expect($server->hasEdgeProxy())->toBeFalse();

    $server->update(['meta' => array_merge($server->meta, ['edge_proxy' => 'traefik'])]);
    $server->refresh();
    expect($server->edgeProxy())->toBe('traefik');
    expect($server->hasEdgeProxy())->toBeTrue();

    // Garbage value yields null (defensive).
    $server->update(['meta' => array_merge($server->meta, ['edge_proxy' => 'nonsense'])]);
    $server->refresh();
    expect($server->edgeProxy())->toBeNull();
});

test('add edge proxy dispatches job and seeds console action', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->call('addEdgeProxy', 'traefik');

    Queue::assertPushed(AddEdgeProxyJob::class, function (AddEdgeProxyJob $job) use ($server) {
        return $job->serverId === $server->id && $job->target === 'traefik';
    });

    $this->assertDatabaseHas('console_actions', [
        'subject_type' => $server->getMorphClass(),
        'subject_id' => $server->id,
        'kind' => 'edge_proxy',
        'status' => ConsoleAction::STATUS_QUEUED,
    ]);
});

test('add edge proxy rejects unknown target', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->call('addEdgeProxy', 'envoy');

    Queue::assertNotPushed(AddEdgeProxyJob::class);
});

test('add edge proxy refuses when action inflight', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user);

    // Pre-seed an in-flight row to simulate a running job.
    ConsoleAction::query()->create([
        'subject_type' => $server->getMorphClass(),
        'subject_id' => $server->id,
        'kind' => 'edge_proxy',
        'status' => ConsoleAction::STATUS_RUNNING,
        'label' => 'In flight',
        'user_id' => $user->id,
        'output' => ['v' => 1, 'lines' => []],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->call('addEdgeProxy', 'haproxy');

    Queue::assertNotPushed(AddEdgeProxyJob::class);
});

test('remove edge proxy dispatches job', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user, ['edge_proxy' => 'haproxy']);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->call('removeEdgeProxy');

    Queue::assertPushed(RemoveEdgeProxyJob::class, function (RemoveEdgeProxyJob $job) use ($server) {
        return $job->serverId === $server->id;
    });
});

test('remove edge proxy no op when none active', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->call('removeEdgeProxy');

    Queue::assertNotPushed(RemoveEdgeProxyJob::class);
});

test('has inflight edge proxy action reads console actions', function () {
    $user = makeUser();
    $server = makeServer($user);

    $component = Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server]);

    // Before: nothing inflight.
    expect($component->instance()->hasInflightEdgeProxyAction())->toBeFalse();

    ConsoleAction::query()->create([
        'subject_type' => $server->getMorphClass(),
        'subject_id' => $server->id,
        'kind' => 'edge_proxy',
        'status' => ConsoleAction::STATUS_QUEUED,
        'label' => 'Queued',
        'user_id' => $user->id,
        'output' => ['v' => 1, 'lines' => []],
    ]);

    expect($component->instance()->hasInflightEdgeProxyAction())->toBeTrue();
});
