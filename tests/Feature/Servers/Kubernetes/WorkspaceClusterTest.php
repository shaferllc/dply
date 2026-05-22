<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\Kubernetes\WorkspaceClusterTest;
use App\Jobs\PollDoksClusterStatusJob;
use App\Livewire\Servers\WorkspaceCluster;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

uses(\Tests\Concerns\WithFeatures::class);

test('poller keeps polling while state is provisioning', function () {
    Cache::flush();
    Queue::fake();
    Http::fake(['api.digitalocean.com/v2/kubernetes/clusters/cluster-id' => Http::response([
        'kubernetes_cluster' => [
            'id' => 'cluster-id',
            'name' => 'prod',
            'region' => 'nyc3',
            'status' => ['state' => 'provisioning'],
            'node_pools' => [['name' => 'workers', 'size' => 's-2vcpu-4gb', 'count' => 2, 'nodes' => []]],
        ],
    ], 200)]);

    [$user, $org] = userWithOrg();
    $server = makeKubernetesServer($user, $org, status: Server::STATUS_PROVISIONING);

    (new PollDoksClusterStatusJob($server))->handle();

    $server->refresh();
    expect($server->status)->toBe(Server::STATUS_PROVISIONING, 'should still be provisioning after a provisioning-state poll');
    expect($server->meta['kubernetes']['state'])->toBe('provisioning');
    expect($server->meta['kubernetes']['snapshot'])->not->toBeNull();
    expect($server->meta['kubernetes']['last_polled_at'])->not->toBeNull();
});
test('poller flips status to ready and stores kubeconfig on running', function () {
    Cache::flush();
    Http::fake([
        'api.digitalocean.com/v2/kubernetes/clusters/cluster-id/kubeconfig' => Http::response('apiVersion: v1', 200),
        'api.digitalocean.com/v2/kubernetes/clusters/cluster-id' => Http::response([
            'kubernetes_cluster' => [
                'id' => 'cluster-id',
                'name' => 'prod',
                'region' => 'nyc3',
                'status' => ['state' => 'running'],
                'node_pools' => [['name' => 'workers', 'size' => 's-2vcpu-4gb', 'count' => 1,
                    'nodes' => [['status' => ['state' => 'running']]]]],
            ],
        ], 200),
    ]);

    [$user, $org] = userWithOrg();
    $server = makeKubernetesServer($user, $org, status: Server::STATUS_PROVISIONING);

    (new PollDoksClusterStatusJob($server))->handle();

    $server->refresh();
    expect($server->status)->toBe(Server::STATUS_READY);
    expect($server->health_status)->toBe(Server::HEALTH_REACHABLE);
    $this->assertStringContainsString('apiVersion', $server->meta['kubernetes']['kubeconfig']);
    expect($server->meta['kubernetes']['kubeconfig_fetched_at'])->not->toBeNull();
});
test('poller flips status to error when do reports error state', function () {
    Cache::flush();
    Http::fake(['api.digitalocean.com/v2/kubernetes/clusters/cluster-id' => Http::response([
        'kubernetes_cluster' => [
            'id' => 'cluster-id', 'name' => 'prod', 'region' => 'nyc3',
            'status' => ['state' => 'error', 'message' => 'quota exceeded'],
            'node_pools' => [],
        ],
    ], 200)]);

    [$user, $org] = userWithOrg();
    $server = makeKubernetesServer($user, $org, status: Server::STATUS_PROVISIONING);

    (new PollDoksClusterStatusJob($server))->handle();

    $server->refresh();
    expect($server->status)->toBe(Server::STATUS_ERROR);
    $this->assertStringContainsString('quota exceeded', $server->meta['kubernetes']['last_error']);
});
test('poller marks error when cluster no longer exists in do', function () {
    Cache::flush();
    Http::fake(['api.digitalocean.com/v2/kubernetes/clusters/cluster-id' => Http::response(null, 404)]);

    [$user, $org] = userWithOrg();
    $server = makeKubernetesServer($user, $org, status: Server::STATUS_PROVISIONING);

    (new PollDoksClusterStatusJob($server))->handle();

    $server->refresh();
    expect($server->status)->toBe(Server::STATUS_ERROR);
    $this->assertStringContainsString('no longer exists', $server->meta['kubernetes']['last_error']);
});
test('cluster page renders provisioning phase with milestone strip', function () {
    Cache::flush();
    Http::fake();

    [$user, $org] = userWithOrg();
    $server = makeKubernetesServer($user, $org, status: Server::STATUS_PROVISIONING, snapshot: [
        'node_pools' => [['name' => 'workers', 'size' => 's-2vcpu-4gb', 'count' => 2,
            'nodes' => [
                ['status' => ['state' => 'running']],
                ['status' => ['state' => 'provisioning']],
            ]]],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceCluster::class, ['server' => $server])
        ->assertSee('DigitalOcean is bringing your cluster online')
        ->assertSee('1 of 2 ready');
});
test('cluster page renders ready phase with kubeconfig and node pool table', function () {
    Cache::flush();
    Http::fake();

    [$user, $org] = userWithOrg();
    $server = makeKubernetesServer($user, $org, status: Server::STATUS_READY, kubeconfig: 'apiVersion: v1', snapshot: [
        'version' => '1.30.1',
        'ha' => false,
        'node_pools' => [['name' => 'workers', 'size' => 's-2vcpu-4gb', 'count' => 2,
            'nodes' => [['status' => ['state' => 'running']], ['status' => ['state' => 'running']]]]],
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceCluster::class, ['server' => $server])
        ->assertSee('Running')
        ->assertSee('Download kubeconfig')
        ->assertSee('s-2vcpu-4gb');
});
test('cluster page renders error phase with retry action', function () {
    Cache::flush();
    Http::fake();

    [$user, $org] = userWithOrg();
    $server = makeKubernetesServer($user, $org, status: Server::STATUS_ERROR, lastError: 'quota exceeded');

    Livewire::actingAs($user)
        ->test(WorkspaceCluster::class, ['server' => $server])
        ->assertSee('Cluster provisioning failed')
        ->assertSee('quota exceeded')
        ->assertSee('Retry polling');
});
test('kubeconfig download endpoint serves yaml when available', function () {
    [$user, $org] = userWithOrg();
    $server = makeKubernetesServer($user, $org, status: Server::STATUS_READY, kubeconfig: "apiVersion: v1\nkind: Config");

    $this->actingAs($user)
        ->get(route('servers.cluster.kubeconfig', $server))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/yaml')
        ->assertSee('apiVersion: v1');
});
test('kubeconfig download returns 404 when not yet fetched', function () {
    [$user, $org] = userWithOrg();
    $server = makeKubernetesServer($user, $org, status: Server::STATUS_PROVISIONING);

    $this->actingAs($user)
        ->get(route('servers.cluster.kubeconfig', $server))
        ->assertNotFound();
});
test('delete cluster provisioned by dply calls do api then removes row', function () {
    Cache::flush();
    Http::fake(['api.digitalocean.com/v2/kubernetes/clusters/cluster-id' => Http::response(null, 204)]);

    [$user, $org] = userWithOrg();
    $server = makeKubernetesServer($user, $org, status: Server::STATUS_READY, provisionedByDply: true);
    $serverId = $server->id;

    Livewire::actingAs($user)
        ->test(WorkspaceCluster::class, ['server' => $server])
        ->set('deleteConfirmName', 'prod-cluster')
        ->call('deleteCluster');

    expect(Server::query()->find($serverId))->toBeNull('server row should be deleted');
    Http::assertSent(fn ($r) => $r->method() === 'DELETE'
        && str_ends_with($r->url(), '/kubernetes/clusters/cluster-id'));
});
test('delete cluster registered existing only removes dply row', function () {
    Cache::flush();
    Http::fake();

    [$user, $org] = userWithOrg();
    $server = makeKubernetesServer($user, $org, status: Server::STATUS_READY, provisionedByDply: false);
    $serverId = $server->id;

    Livewire::actingAs($user)
        ->test(WorkspaceCluster::class, ['server' => $server])
        ->call('deleteCluster');

    expect(Server::query()->find($serverId))->toBeNull('server row should be deleted');

    // Crucially: no DELETE to DO API for registered clusters.
    Http::assertNotSent(fn ($r) => $r->method() === 'DELETE'
        && str_contains($r->url(), '/kubernetes/clusters/'));
});
test('delete cluster provisioned requires typed name confirmation', function () {
    Cache::flush();
    Http::fake();

    [$user, $org] = userWithOrg();
    $server = makeKubernetesServer($user, $org, status: Server::STATUS_READY, provisionedByDply: true);
    $serverId = $server->id;

    Livewire::actingAs($user)
        ->test(WorkspaceCluster::class, ['server' => $server])
        ->set('deleteConfirmName', 'wrong-name')
        ->call('deleteCluster')
        ->assertHasErrors('deleteConfirmName');

    expect(Server::query()->find($serverId))->not->toBeNull('server row should NOT have been deleted');
    Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
});
test('cluster page shows container launch banner when one is in flight', function () {
    Cache::flush();
    Http::fake();

    [$user, $org] = userWithOrg();
    $server = makeKubernetesServer($user, $org, status: Server::STATUS_READY);

    // Simulate that storeContainer() flagged an in-flight launch on meta.
    $meta = $server->meta;
    $meta['container_launch'] = [
        'status' => 'creating_site',
        'target_family' => 'cloud_kubernetes',
        'current_step_label' => 'Creating site record',
        'summary' => 'Dply is creating the site record before queueing the deploy.',
        'events' => [['at' => now()->toIso8601String(), 'level' => 'info', 'message' => 'Container app launch queued.']],
    ];
    $server->update(['meta' => $meta]);

    Livewire::actingAs($user)
        ->test(WorkspaceCluster::class, ['server' => $server])
        ->assertSeeHtml('data-testid="container-launch-progress"')
        ->assertSee('Creating site record');
});
test('refresh button dispatches one shot poll', function () {
    Cache::flush();
    Queue::fake();
    Http::fake();

    [$user, $org] = userWithOrg();
    $server = makeKubernetesServer($user, $org, status: Server::STATUS_READY);

    Livewire::actingAs($user)
        ->test(WorkspaceCluster::class, ['server' => $server])
        ->call('refreshClusterStatus');

    Queue::assertPushed(PollDoksClusterStatusJob::class);
});
test('k8s servers get lean nav without php databases caches etc', function () {
    [$user, $org] = userWithOrg();
    $server = makeKubernetesServer($user, $org, status: Server::STATUS_READY);

    $items = server_workspace_nav_for_server($server);
    $keys = array_column($items, 'key');

    expect($keys)->toContain('cluster');
    expect($keys)->not->toContain('overview');
    expect($keys)->not->toContain('php');
    expect($keys)->not->toContain('databases');
    expect($keys)->not->toContain('caches');
    expect($keys)->not->toContain('webserver');
    expect($keys)->not->toContain('cron');
    expect($keys)->not->toContain('monitor');
});
test('vm servers do not see cluster nav item', function () {
    [$user, $org] = userWithOrg();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => 'vm'],
    ]);

    $keys = array_column(server_workspace_nav_for_server($server), 'key');

    expect($keys)->not->toContain('cluster');
    expect($keys)->toContain('overview');
});
/**
 * @return array{0: User, 1: Organization}
 */
function userWithOrg(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $user->setRelation('currentOrganization', $org);
    session(['current_organization_id' => $org->id]);

    return [$user, $org];
}
/**
 * @param  array<string, mixed>  $snapshot
 */
function makeKubernetesServer(User $user, Organization $org, string $status = Server::STATUS_PROVISIONING, ?string $kubeconfig = null, ?string $lastError = null, array $snapshot = [], bool $provisionedByDply = true): Server
{
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 't'],
    ]);

    return Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'status' => $status,
        'meta' => [
            'host_kind' => Server::HOST_KIND_KUBERNETES,
            'kubernetes' => array_filter([
                'provider' => 'digitalocean',
                'cluster_name' => 'prod-cluster',
                'cluster_id' => 'cluster-id',
                'region' => 'nyc3',
                'namespace' => 'default',
                'provisioned_by_dply' => $provisionedByDply,
                'kubeconfig' => $kubeconfig,
                'kubeconfig_fetched_at' => $kubeconfig ? now()->toIso8601String() : null,
                'last_error' => $lastError,
                'snapshot' => $snapshot !== [] ? $snapshot : null,
                // A snapshot implies a recent poll, so stamp last_polled_at
                // alongside it. Otherwise the WorkspaceCluster mount-time
                // freshness check treats the row as stale and re-polls
                // synchronously, which would overwrite the fixture
                // snapshot with whatever Http::fake() returns.
                'last_polled_at' => $snapshot !== [] ? now()->toIso8601String() : null,
            ], static fn ($v): bool => $v !== null),
        ],
    ]);
}
