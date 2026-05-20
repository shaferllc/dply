<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\Kubernetes;

use App\Jobs\PollDoksClusterStatusJob;
use App\Livewire\Servers\WorkspaceCluster;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;
use Tests\TestCase;

/**
 * Covers the dedicated K8s cluster page: provisioning / ready / error phases,
 * the poller's lifecycle transitions, the kubeconfig download endpoint, and
 * the adaptive delete-cluster flow.
 */
final class WorkspaceClusterTest extends TestCase
{
    use RefreshDatabase;
    use WithFeatures;

    protected array $features = ['workspace.cluster'];

    public function test_poller_keeps_polling_while_state_is_provisioning(): void
    {
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

        [$user, $org] = $this->userWithOrg();
        $server = $this->makeKubernetesServer($user, $org, status: Server::STATUS_PROVISIONING);

        (new PollDoksClusterStatusJob($server))->handle();

        $server->refresh();
        $this->assertSame(Server::STATUS_PROVISIONING, $server->status, 'should still be provisioning after a provisioning-state poll');
        $this->assertSame('provisioning', $server->meta['kubernetes']['state']);
        $this->assertNotNull($server->meta['kubernetes']['snapshot']);
        $this->assertNotNull($server->meta['kubernetes']['last_polled_at']);
    }

    public function test_poller_flips_status_to_ready_and_stores_kubeconfig_on_running(): void
    {
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

        [$user, $org] = $this->userWithOrg();
        $server = $this->makeKubernetesServer($user, $org, status: Server::STATUS_PROVISIONING);

        (new PollDoksClusterStatusJob($server))->handle();

        $server->refresh();
        $this->assertSame(Server::STATUS_READY, $server->status);
        $this->assertSame(Server::HEALTH_REACHABLE, $server->health_status);
        $this->assertStringContainsString('apiVersion', $server->meta['kubernetes']['kubeconfig']);
        $this->assertNotNull($server->meta['kubernetes']['kubeconfig_fetched_at']);
    }

    public function test_poller_flips_status_to_error_when_do_reports_error_state(): void
    {
        Cache::flush();
        Http::fake(['api.digitalocean.com/v2/kubernetes/clusters/cluster-id' => Http::response([
            'kubernetes_cluster' => [
                'id' => 'cluster-id', 'name' => 'prod', 'region' => 'nyc3',
                'status' => ['state' => 'error', 'message' => 'quota exceeded'],
                'node_pools' => [],
            ],
        ], 200)]);

        [$user, $org] = $this->userWithOrg();
        $server = $this->makeKubernetesServer($user, $org, status: Server::STATUS_PROVISIONING);

        (new PollDoksClusterStatusJob($server))->handle();

        $server->refresh();
        $this->assertSame(Server::STATUS_ERROR, $server->status);
        $this->assertStringContainsString('quota exceeded', $server->meta['kubernetes']['last_error']);
    }

    public function test_poller_marks_error_when_cluster_no_longer_exists_in_do(): void
    {
        Cache::flush();
        Http::fake(['api.digitalocean.com/v2/kubernetes/clusters/cluster-id' => Http::response(null, 404)]);

        [$user, $org] = $this->userWithOrg();
        $server = $this->makeKubernetesServer($user, $org, status: Server::STATUS_PROVISIONING);

        (new PollDoksClusterStatusJob($server))->handle();

        $server->refresh();
        $this->assertSame(Server::STATUS_ERROR, $server->status);
        $this->assertStringContainsString('no longer exists', $server->meta['kubernetes']['last_error']);
    }

    public function test_cluster_page_renders_provisioning_phase_with_milestone_strip(): void
    {
        Cache::flush();
        Http::fake();

        [$user, $org] = $this->userWithOrg();
        $server = $this->makeKubernetesServer($user, $org, status: Server::STATUS_PROVISIONING, snapshot: [
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
    }

    public function test_cluster_page_renders_ready_phase_with_kubeconfig_and_node_pool_table(): void
    {
        Cache::flush();
        Http::fake();

        [$user, $org] = $this->userWithOrg();
        $server = $this->makeKubernetesServer($user, $org, status: Server::STATUS_READY, kubeconfig: 'apiVersion: v1', snapshot: [
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
    }

    public function test_cluster_page_renders_error_phase_with_retry_action(): void
    {
        Cache::flush();
        Http::fake();

        [$user, $org] = $this->userWithOrg();
        $server = $this->makeKubernetesServer($user, $org, status: Server::STATUS_ERROR, lastError: 'quota exceeded');

        Livewire::actingAs($user)
            ->test(WorkspaceCluster::class, ['server' => $server])
            ->assertSee('Cluster provisioning failed')
            ->assertSee('quota exceeded')
            ->assertSee('Retry polling');
    }

    public function test_kubeconfig_download_endpoint_serves_yaml_when_available(): void
    {
        [$user, $org] = $this->userWithOrg();
        $server = $this->makeKubernetesServer($user, $org, status: Server::STATUS_READY, kubeconfig: "apiVersion: v1\nkind: Config");

        $this->actingAs($user)
            ->get(route('servers.cluster.kubeconfig', $server))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/yaml')
            ->assertSee('apiVersion: v1');
    }

    public function test_kubeconfig_download_returns_404_when_not_yet_fetched(): void
    {
        [$user, $org] = $this->userWithOrg();
        $server = $this->makeKubernetesServer($user, $org, status: Server::STATUS_PROVISIONING);

        $this->actingAs($user)
            ->get(route('servers.cluster.kubeconfig', $server))
            ->assertNotFound();
    }

    public function test_delete_cluster_provisioned_by_dply_calls_do_api_then_removes_row(): void
    {
        Cache::flush();
        Http::fake(['api.digitalocean.com/v2/kubernetes/clusters/cluster-id' => Http::response(null, 204)]);

        [$user, $org] = $this->userWithOrg();
        $server = $this->makeKubernetesServer($user, $org, status: Server::STATUS_READY, provisionedByDply: true);
        $serverId = $server->id;

        Livewire::actingAs($user)
            ->test(WorkspaceCluster::class, ['server' => $server])
            ->set('deleteConfirmName', 'prod-cluster')
            ->call('deleteCluster');

        $this->assertNull(Server::query()->find($serverId), 'server row should be deleted');
        Http::assertSent(fn ($r) => $r->method() === 'DELETE'
            && str_ends_with($r->url(), '/kubernetes/clusters/cluster-id'));
    }

    public function test_delete_cluster_registered_existing_only_removes_dply_row(): void
    {
        Cache::flush();
        Http::fake();

        [$user, $org] = $this->userWithOrg();
        $server = $this->makeKubernetesServer($user, $org, status: Server::STATUS_READY, provisionedByDply: false);
        $serverId = $server->id;

        Livewire::actingAs($user)
            ->test(WorkspaceCluster::class, ['server' => $server])
            ->call('deleteCluster');

        $this->assertNull(Server::query()->find($serverId), 'server row should be deleted');
        // Crucially: no DELETE to DO API for registered clusters.
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE'
            && str_contains($r->url(), '/kubernetes/clusters/'));
    }

    public function test_delete_cluster_provisioned_requires_typed_name_confirmation(): void
    {
        Cache::flush();
        Http::fake();

        [$user, $org] = $this->userWithOrg();
        $server = $this->makeKubernetesServer($user, $org, status: Server::STATUS_READY, provisionedByDply: true);
        $serverId = $server->id;

        Livewire::actingAs($user)
            ->test(WorkspaceCluster::class, ['server' => $server])
            ->set('deleteConfirmName', 'wrong-name')
            ->call('deleteCluster')
            ->assertHasErrors('deleteConfirmName');

        $this->assertNotNull(Server::query()->find($serverId), 'server row should NOT have been deleted');
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_cluster_page_shows_container_launch_banner_when_one_is_in_flight(): void
    {
        Cache::flush();
        Http::fake();

        [$user, $org] = $this->userWithOrg();
        $server = $this->makeKubernetesServer($user, $org, status: Server::STATUS_READY);
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
    }

    public function test_refresh_button_dispatches_one_shot_poll(): void
    {
        Cache::flush();
        Queue::fake();
        Http::fake();

        [$user, $org] = $this->userWithOrg();
        $server = $this->makeKubernetesServer($user, $org, status: Server::STATUS_READY);

        Livewire::actingAs($user)
            ->test(WorkspaceCluster::class, ['server' => $server])
            ->call('refreshClusterStatus');

        Queue::assertPushed(PollDoksClusterStatusJob::class);
    }

    public function test_k8s_servers_get_lean_nav_without_php_databases_caches_etc(): void
    {
        [$user, $org] = $this->userWithOrg();
        $server = $this->makeKubernetesServer($user, $org, status: Server::STATUS_READY);

        $items = server_workspace_nav_for_server($server);
        $keys = array_column($items, 'key');

        $this->assertContains('cluster', $keys, 'Cluster nav item should be present for K8s servers');
        $this->assertNotContains('overview', $keys, 'overview should be hidden for K8s servers');
        $this->assertNotContains('php', $keys);
        $this->assertNotContains('databases', $keys);
        $this->assertNotContains('caches', $keys);
        $this->assertNotContains('webserver', $keys);
        $this->assertNotContains('cron', $keys);
        $this->assertNotContains('monitor', $keys);
    }

    public function test_vm_servers_do_not_see_cluster_nav_item(): void
    {
        [$user, $org] = $this->userWithOrg();
        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'meta' => ['host_kind' => 'vm'],
        ]);

        $keys = array_column(server_workspace_nav_for_server($server), 'key');

        $this->assertNotContains('cluster', $keys);
        $this->assertContains('overview', $keys);
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function userWithOrg(): array
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
    private function makeKubernetesServer(
        User $user,
        Organization $org,
        string $status = Server::STATUS_PROVISIONING,
        ?string $kubeconfig = null,
        ?string $lastError = null,
        array $snapshot = [],
        bool $provisionedByDply = true,
    ): Server {
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
}
