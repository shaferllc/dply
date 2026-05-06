<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\InstallCacheServiceJob;
use App\Livewire\Servers\WorkspaceCaches;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerCacheServiceAuditEvent;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\CacheServiceStats;
use App\Support\Servers\ServerCacheServiceHostCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature tests for `addInstance` — the multi-port flow that lets operators
 * install a second/third/Nth instance of the same engine on a different port.
 * The default-instance install path (single-instance, legacy paths) is covered
 * by the existing WorkspaceCachesTest::test_install_dispatches_job_and_creates_row.
 */
class WorkspaceCachesAddInstanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->mock(CacheServiceStats::class, function ($mock): void {
            $mock->shouldReceive('snapshot')->byDefault()->andReturn([]);
            $mock->shouldReceive('forget')->byDefault()->andReturnNull();
        });

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->byDefault()->andReturn([
                'redis' => true, 'valkey' => false, 'memcached' => false,
                'keydb' => false, 'dragonfly' => false,
            ]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });
    }

    /**
     * @return array{User, Server}
     */
    protected function actingOwnerWithServer(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'ssh_user' => 'root',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1lZDI1NTE5AAAA\n-----END OPENSSH PRIVATE KEY-----",
        ]);

        return [$user, $server];
    }

    public function test_add_instance_creates_named_row_and_dispatches_job(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        // Existing default-named instance on 6379.
        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('addInstance', 'redis', 'sessions', 6380)
            ->assertHasNoErrors();

        $row = ServerCacheService::query()
            ->where('server_id', $server->id)
            ->where('engine', 'redis')
            ->where('name', 'sessions')
            ->firstOrFail();

        $this->assertSame(6380, $row->port);
        $this->assertSame(ServerCacheService::STATUS_PENDING, $row->status);

        Queue::assertPushed(InstallCacheServiceJob::class, fn ($job) => true);
    }

    public function test_add_instance_rejects_default_name(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('addInstance', 'redis', 'default', 6380);

        $this->assertSame(0, ServerCacheService::query()->count());
        Queue::assertNotPushed(InstallCacheServiceJob::class);
    }

    public function test_add_instance_rejects_invalid_name_shape(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('addInstance', 'redis', 'Bad_Name', 6380);

        $this->assertSame(0, ServerCacheService::query()->count());
    }

    public function test_add_instance_rejects_memcached(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('addInstance', 'memcached', 'extra', 11212);

        $this->assertSame(0, ServerCacheService::query()->count());
    }

    public function test_add_instance_rejects_duplicate_port_on_same_server(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('addInstance', 'redis', 'sessions', 6379);

        // Still only the original row.
        $this->assertSame(1, ServerCacheService::query()->count());
    }

    public function test_add_instance_rejects_duplicate_name_within_engine(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => 'sessions',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6380,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('addInstance', 'redis', 'sessions', 6381);

        $this->assertSame(1, ServerCacheService::query()->count());
    }

    public function test_add_instance_blocked_when_another_change_is_in_flight(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
            'status' => ServerCacheService::STATUS_INSTALLING,
            'port' => 6379,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('addInstance', 'redis', 'sessions', 6380);

        // No new row was created — the busy guard rejected.
        $this->assertSame(1, ServerCacheService::query()->count());
        Queue::assertNotPushed(InstallCacheServiceJob::class);
    }

    public function test_add_instance_rejects_reserved_port(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('addInstance', 'redis', 'http', 80);

        $this->assertSame(0, ServerCacheService::query()->count());
    }

    public function test_add_instance_rejects_out_of_range_port(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('addInstance', 'redis', 'oops', 70000);

        $this->assertSame(0, ServerCacheService::query()->count());
    }

    public function test_set_active_instance_routes_actions_to_named_row(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);
        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => 'sessions',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6380,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->assertSet('active_instance', ServerCacheService::DEFAULT_INSTANCE_NAME)
            ->call('setActiveInstance', 'sessions')
            ->assertSet('active_instance', 'sessions')
            ->call('setActiveInstance', 'nonexistent')
            ->assertSet('active_instance', ServerCacheService::DEFAULT_INSTANCE_NAME, 'unknown instance falls back to default');
    }

    public function test_switching_engine_tabs_resets_active_instance(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => 'sessions',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6380,
        ]);

        // Active on redis/sessions, then switch to valkey tab — active must reset
        // to default since 'sessions' doesn't exist on valkey.
        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->call('setActiveInstance', 'sessions')
            ->assertSet('active_instance', 'sessions')
            ->call('setWorkspaceTab', 'valkey')
            ->assertSet('active_instance', ServerCacheService::DEFAULT_INSTANCE_NAME);
    }

    public function test_open_add_instance_form_suggests_next_free_port(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        // Default instance on 6379 already; first free port for redis-family is 6380.
        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->call('openAddInstanceForm')
            ->assertSet('showAddInstanceForm', true)
            ->assertSet('newInstancePort', 6380);
    }

    public function test_open_add_instance_form_skips_existing_named_instance_ports(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        // Default + named instance already running. Next free is 6381.
        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);
        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => 'sessions',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6380,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->call('openAddInstanceForm')
            ->assertSet('newInstancePort', 6381);
    }

    public function test_close_add_instance_form_clears_inputs(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->call('openAddInstanceForm')
            ->set('newInstanceName', 'sessions')
            ->call('closeAddInstanceForm')
            ->assertSet('showAddInstanceForm', false)
            ->assertSet('newInstanceName', '')
            ->assertSet('newInstancePort', null);
    }

    public function test_install_auto_picks_next_free_port_when_default_is_taken(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        // Existing redis on the default 6379. Installing Valkey would normally
        // also default to 6379 (wire-compatible) — the install flow must walk
        // up to 6380 instead of crashing on the (server_id, port) unique key.
        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn([
                'redis' => true, 'valkey' => true, 'memcached' => false,
                'keydb' => false, 'dragonfly' => false,
            ]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('installCacheService', 'valkey')
            ->assertHasNoErrors();

        $valkey = ServerCacheService::query()
            ->where('server_id', $server->id)
            ->where('engine', 'valkey')
            ->firstOrFail();

        $this->assertSame(6380, $valkey->port, 'Valkey should land on 6380 because 6379 is taken by Redis.');
    }

    public function test_install_uses_default_port_when_free(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('installCacheService', 'redis')
            ->assertHasNoErrors();

        $row = ServerCacheService::query()
            ->where('server_id', $server->id)
            ->where('engine', 'redis')
            ->firstOrFail();

        $this->assertSame(6379, $row->port, 'First redis install should land on the default 6379.');
    }

    public function test_next_free_port_helper_walks_past_named_instances(): void
    {
        [, $server] = $this->actingOwnerWithServer();

        // Both default 6379 and named 6380 in use.
        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);
        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => 'sessions',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6380,
        ]);

        $this->assertSame(
            6381,
            ServerCacheService::nextFreePortFor($server->id, 'valkey'),
            'Walk past 6379 (redis/default) and 6380 (redis/sessions) to land on 6381.',
        );
    }

    public function test_per_engine_action_against_named_instance_records_instance_name_in_audit_meta(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);
        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => 'sessions',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6380,
        ]);

        // Restart action against the named instance.
        $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
            $mock->shouldReceive('runInlineBash')->andReturn(new ProcessOutput('OK', 0, false));
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->call('setActiveInstance', 'sessions')
            ->call('restartCacheService', 'redis');

        $event = ServerCacheServiceAuditEvent::query()
            ->where('server_id', $server->id)
            ->where('event', ServerCacheServiceAuditEvent::EVENT_RESTARTED)
            ->latest()
            ->firstOrFail();

        $this->assertSame('redis', $event->meta['engine']);
        $this->assertSame('sessions', $event->meta['name']);
    }

    public function test_submit_add_instance_form_creates_row_and_switches_to_it(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->set('newInstanceName', 'sessions')
            ->set('newInstancePort', 6380)
            ->set('showAddInstanceForm', true)
            ->call('submitAddInstanceForm')
            ->assertSet('showAddInstanceForm', false)
            ->assertSet('active_instance', 'sessions');

        $this->assertDatabaseHas('server_cache_services', [
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => 'sessions',
            'port' => 6380,
            'status' => ServerCacheService::STATUS_PENDING,
        ]);
    }
}
