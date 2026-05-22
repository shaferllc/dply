<?php

namespace Tests\Feature;

use App\Jobs\AddEdgeProxyJob;
use App\Jobs\RemoveEdgeProxyJob;
use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\ConsoleAction;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;
use Tests\TestCase;

/**
 * Covers the Edge Proxy add/remove surface — separate from the webserver
 * switch flow. Edge proxies (Traefik, HAProxy) sit IN FRONT of the
 * webserver on :80 and route hosts to per-site Caddy backends on
 * ephemeral high ports.
 */
class EdgeProxyTest extends TestCase
{
    use RefreshDatabase;
    use WithFeatures;

    protected array $features = ['surface.cloud'];

    private function makeUser(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $user->update(['current_organization_id' => $org->id]);
        session(['current_organization_id' => $org->id]);

        return $user->fresh();
    }

    private function makeServer(User $user, array $metaOverrides = []): Server
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

    public function test_server_edge_proxy_helpers(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer($user);
        $this->assertNull($server->edgeProxy());
        $this->assertFalse($server->hasEdgeProxy());

        $server->update(['meta' => array_merge($server->meta, ['edge_proxy' => 'traefik'])]);
        $server->refresh();
        $this->assertSame('traefik', $server->edgeProxy());
        $this->assertTrue($server->hasEdgeProxy());

        // Garbage value yields null (defensive).
        $server->update(['meta' => array_merge($server->meta, ['edge_proxy' => 'nonsense'])]);
        $server->refresh();
        $this->assertNull($server->edgeProxy());
    }

    public function test_add_edge_proxy_dispatches_job_and_seeds_console_action(): void
    {
        Queue::fake();
        $user = $this->makeUser();
        $server = $this->makeServer($user);

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
    }

    public function test_add_edge_proxy_rejects_unknown_target(): void
    {
        Queue::fake();
        $user = $this->makeUser();
        $server = $this->makeServer($user);

        Livewire::actingAs($user)
            ->test(WorkspaceWebserver::class, ['server' => $server])
            ->call('addEdgeProxy', 'envoy');

        Queue::assertNotPushed(AddEdgeProxyJob::class);
    }

    public function test_add_edge_proxy_refuses_when_action_inflight(): void
    {
        Queue::fake();
        $user = $this->makeUser();
        $server = $this->makeServer($user);

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
    }

    public function test_remove_edge_proxy_dispatches_job(): void
    {
        Queue::fake();
        $user = $this->makeUser();
        $server = $this->makeServer($user, ['edge_proxy' => 'haproxy']);

        Livewire::actingAs($user)
            ->test(WorkspaceWebserver::class, ['server' => $server])
            ->call('removeEdgeProxy');

        Queue::assertPushed(RemoveEdgeProxyJob::class, function (RemoveEdgeProxyJob $job) use ($server) {
            return $job->serverId === $server->id;
        });
    }

    public function test_remove_edge_proxy_no_op_when_none_active(): void
    {
        Queue::fake();
        $user = $this->makeUser();
        $server = $this->makeServer($user);

        Livewire::actingAs($user)
            ->test(WorkspaceWebserver::class, ['server' => $server])
            ->call('removeEdgeProxy');

        Queue::assertNotPushed(RemoveEdgeProxyJob::class);
    }

    public function test_has_inflight_edge_proxy_action_reads_console_actions(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer($user);

        $component = Livewire::actingAs($user)
            ->test(WorkspaceWebserver::class, ['server' => $server]);

        // Before: nothing inflight.
        $this->assertFalse($component->instance()->hasInflightEdgeProxyAction());

        ConsoleAction::query()->create([
            'subject_type' => $server->getMorphClass(),
            'subject_id' => $server->id,
            'kind' => 'edge_proxy',
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => 'Queued',
            'user_id' => $user->id,
            'output' => ['v' => 1, 'lines' => []],
        ]);

        $this->assertTrue($component->instance()->hasInflightEdgeProxyAction());
    }
}
