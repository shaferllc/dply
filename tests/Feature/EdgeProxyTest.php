<?php

namespace Tests\Feature\EdgeProxyTest;

use App\Jobs\AddEdgeProxyJob;
use App\Jobs\ApplyEdgeBackendConfigsJob;
use App\Jobs\RemoveEdgeProxyJob;
use App\Livewire\Servers\WorkspaceEdgeProxy;
use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\ConsoleAction;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\EnvoyStaticConfigOptions;
use App\Support\Servers\EdgeProxyWorkspaceViewData;
use App\Support\Servers\ServerConsoleActionLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['server_workspace.edge_proxy_coming_soon' => []]);
});

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
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
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

    $server->update(['meta' => array_merge($server->meta, ['edge_proxy' => 'envoy'])]);
    $server->refresh();
    expect($server->edgeProxy())->toBe('envoy');

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
        ->test(WorkspaceEdgeProxy::class, ['server' => $server])
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

test('switch edge proxy dispatches add job when another is active', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user, [
        'edge_proxy' => 'traefik',
        'edge_proxy_previous_webserver' => 'nginx',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceEdgeProxy::class, ['server' => $server])
        ->set('workspace_tab', 'haproxy')
        ->assertSee(__('Switch to HAProxy'))
        ->call('addEdgeProxy', 'haproxy')
        ->assertSee(__('Switching edge proxy to HAProxy …'))
        ->assertSee(__('Switching to :name…', ['name' => 'HAProxy']));

    Queue::assertPushed(AddEdgeProxyJob::class, function (AddEdgeProxyJob $job) use ($server) {
        return $job->serverId === $server->id && $job->target === 'haproxy';
    });
});

test('add edge proxy rejects unknown target', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceEdgeProxy::class, ['server' => $server])
        ->call('addEdgeProxy', 'nginx');

    Queue::assertNotPushed(AddEdgeProxyJob::class);
});

test('add edge proxy dispatches job for envoy', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceEdgeProxy::class, ['server' => $server])
        ->call('addEdgeProxy', 'envoy');

    Queue::assertPushed(AddEdgeProxyJob::class, function (AddEdgeProxyJob $job) use ($server) {
        return $job->serverId === $server->id && $job->target === 'envoy';
    });
});

test('envoy live state shows standby copy when another edge proxy is active', function () {
    $user = makeUser();
    $server = makeServer($user, [
        'edge_proxy' => 'haproxy',
        'edge_proxy_previous_webserver' => 'nginx',
        'webserver' => 'caddy',
    ]);
    $server->update(['ssh_private_key' => 'test-key']);

    Livewire::actingAs($user)
        ->test(WorkspaceEdgeProxy::class, ['server' => $server])
        ->set('workspace_tab', 'envoy')
        ->set('engine_subtab', 'listeners')
        ->assertSee(__('HAProxy is the active edge proxy'))
        ->call('refreshEngineLiveState')
        ->assertDontSee('Envoy admin interface unavailable');

    $server->refresh();
    expect(data_get($server->meta, 'webserver_live_state.envoy.engine_specific.standby'))->toBeTrue()
        ->and(data_get($server->meta, 'webserver_live_state.envoy.engine_specific.standby_reason'))->toContain('HAProxy');
});

test('switch edge proxy to envoy dispatches add job when another is active', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user, [
        'edge_proxy' => 'haproxy',
        'edge_proxy_previous_webserver' => 'nginx',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceEdgeProxy::class, ['server' => $server])
        ->set('workspace_tab', 'envoy')
        ->assertSee(__('Switch to Envoy'))
        ->call('addEdgeProxy', 'envoy')
        ->assertSee(__('Switching edge proxy to Envoy …'))
        ->assertSee(__('Switching to :name…', ['name' => 'Envoy']));

    Queue::assertPushed(AddEdgeProxyJob::class, function (AddEdgeProxyJob $job) use ($server) {
        return $job->serverId === $server->id && $job->target === 'envoy';
    });
});

test('add edge proxy rejects catalog preview engines without install job', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceEdgeProxy::class, ['server' => $server])
        ->call('addEdgeProxy', 'openresty');

    Queue::assertNotPushed(AddEdgeProxyJob::class);
});

test('add edge proxy refuses when action inflight', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user);

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
        ->test(WorkspaceEdgeProxy::class, ['server' => $server])
        ->call('addEdgeProxy', 'haproxy');

    Queue::assertNotPushed(AddEdgeProxyJob::class);
});

test('remove edge proxy dispatches job', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user, ['edge_proxy' => 'haproxy']);

    Livewire::actingAs($user)
        ->test(WorkspaceEdgeProxy::class, ['server' => $server])
        ->call('removeEdgeProxy');

    Queue::assertPushed(RemoveEdgeProxyJob::class, function (RemoveEdgeProxyJob $job) use ($server) {
        return $job->serverId === $server->id;
    });
});

test('remove envoy edge proxy dispatches job', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user, [
        'edge_proxy' => 'envoy',
        'edge_proxy_previous_webserver' => 'nginx',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceEdgeProxy::class, ['server' => $server])
        ->call('removeEdgeProxy');

    Queue::assertPushed(RemoveEdgeProxyJob::class, fn (RemoveEdgeProxyJob $job) => $job->serverId === $server->id);
});

test('remove traefik edge proxy dispatches job', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user, [
        'edge_proxy' => 'traefik',
        'edge_proxy_previous_webserver' => 'nginx',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceEdgeProxy::class, ['server' => $server])
        ->call('removeEdgeProxy');

    Queue::assertPushed(RemoveEdgeProxyJob::class, fn (RemoveEdgeProxyJob $job) => $job->serverId === $server->id);
});

test('traefik overview shows remove panel when active', function () {
    $user = makeUser();
    $server = makeServer($user, [
        'edge_proxy' => 'traefik',
        'edge_proxy_previous_webserver' => 'nginx',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceEdgeProxy::class, ['server' => $server])
        ->set('workspace_tab', 'traefik')
        ->assertSee('Remove Traefik')
        ->assertSee('restore nginx');
});

test('remove edge proxy no op when none active', function () {
    Queue::fake();
    $user = makeUser();
    $server = makeServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceEdgeProxy::class, ['server' => $server])
        ->call('removeEdgeProxy');

    Queue::assertNotPushed(RemoveEdgeProxyJob::class);
});

test('has inflight edge proxy action reads console actions', function () {
    $user = makeUser();
    $server = makeServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceEdgeProxy::class, ['server' => $server])
        ->tap(function ($component) {
            expect($component->instance()->hasInflightEdgeProxyAction())->toBeFalse();
        });

    ConsoleAction::query()->create([
        'subject_type' => $server->getMorphClass(),
        'subject_id' => $server->id,
        'kind' => 'edge_proxy',
        'status' => ConsoleAction::STATUS_QUEUED,
        'label' => 'Queued',
        'user_id' => $user->id,
        'output' => ['v' => 1, 'lines' => []],
    ]);

    app()->forgetInstance(ServerConsoleActionLookup::class);

    Livewire::actingAs($user)
        ->test(WorkspaceEdgeProxy::class, ['server' => $server])
        ->tap(function ($component) {
            expect($component->instance()->hasInflightEdgeProxyAction())->toBeTrue();
        });
});

test('edge proxy workspace shows coming soon and preview for gated engines', function () {
    config(['server_workspace.edge_proxy_coming_soon' => ['traefik', 'haproxy', 'envoy', 'openresty']]);

    $user = makeUser();
    $server = makeServer($user);

    $this->actingAs($user)
        ->get(route('servers.edge-proxy', $server).'?tab=change')
        ->assertOk()
        ->assertSee('Edge proxy')
        ->assertSee('Coming soon')
        ->assertSee('Preview')
        ->assertSee('Envoy')
        ->assertSee('OpenResty')
        ->assertDontSee('Add Traefik')
        ->assertDontSee('Add HAProxy');
});

test('webserver workspace no longer lists edge proxy engines in tabs', function () {
    $user = makeUser();
    $server = makeServer($user);

    $this->actingAs($user)
        ->get(route('servers.webserver', $server))
        ->assertOk()
        ->assertSee('nginx')
        ->assertDontSee('Switch to Traefik')
        ->assertDontSee('ws-tab-traefik');
});

test('add edge proxy rejects coming soon target', function () {
    config(['server_workspace.edge_proxy_coming_soon' => ['traefik']]);

    Queue::fake();
    $user = makeUser();
    $server = makeServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceEdgeProxy::class, ['server' => $server])
        ->call('addEdgeProxy', 'traefik');

    Queue::assertNotPushed(AddEdgeProxyJob::class);
});

test('preview tab opens coming soon edge proxy panel', function () {
    config(['server_workspace.edge_proxy_coming_soon' => ['traefik']]);

    $user = makeUser();
    $server = makeServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceEdgeProxy::class, ['server' => $server])
        ->call('setWorkspaceTab', 'traefik')
        ->assertSet('workspace_tab', 'traefik')
        ->assertSee('Router inspector');
});

test('webserver workspace supports change and health tabs', function () {
    $user = makeUser();
    $server = makeServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceWebserver::class, ['server' => $server])
        ->call('setWorkspaceTab', 'change')
        ->assertSet('workspace_tab', 'change')
        ->assertSee('Switch webserver')
        ->assertDontSee('Add Traefik')
        ->call('setWorkspaceTab', 'health')
        ->assertSet('workspace_tab', 'health')
        ->assertSee('TLS certificates on this server')
        ->call('setWorkspaceTab', 'not-a-tab')
        ->assertSet('workspace_tab', 'overview');
});

test('edge proxy route loads workspace', function () {
    $user = makeUser();
    $server = makeServer($user);

    $this->actingAs($user)
        ->get(route('servers.edge-proxy', $server))
        ->assertOk()
        ->assertSee('Edge proxy');
});

test('envoy is installable when not coming soon', function () {
    config(['server_workspace.edge_proxy_coming_soon' => []]);

    expect(EdgeProxyWorkspaceViewData::installableEdgeProxies())->toContain('envoy');
});

test('envoy install script downloads pinned release binary', function () {
    $script = EnvoyStaticConfigOptions::installScript();

    expect($script)->toContain('/usr/local/bin/envoy');
    expect($script)->toContain('ENVOY_VERSION:-1.32.6');
    expect($script)->toContain('envoy-${ENVOY_VERSION}-linux-${DPLY_ARCH}');
    expect($script)->toContain('/etc/envoy/envoy.yaml');
});

test('apply edge backend configs dispatches sync job from envoy workspace', function () {
    Queue::fake();

    $user = makeUser();
    $server = makeServer($user, [
        'edge_proxy' => 'envoy',
        'manage_units' => [['unit' => 'envoy', 'active_state' => 'active']],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceEdgeProxy::class, ['server' => $server])
        ->set('workspace_tab', 'envoy')
        ->call('runAllowlistedAction', 'apply_edge_backend_configs');

    Queue::assertPushed(ApplyEdgeBackendConfigsJob::class, function ($job) use ($server): bool {
        return $job->serverId === $server->id;
    });

    expect(ConsoleAction::query()
        ->where('subject_id', $server->id)
        ->where('kind', 'edge_proxy')
        ->latest()
        ->value('status'))->toBe(ConsoleAction::STATUS_QUEUED);
});
