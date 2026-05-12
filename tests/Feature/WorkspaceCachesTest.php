<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\InstallCacheServiceJob;
use App\Jobs\UninstallCacheServiceJob;
use App\Livewire\Servers\WorkspaceCaches;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerCacheServiceAuditEvent;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\CacheServiceAuth;
use App\Support\Servers\CacheServiceConfigWriter;
use App\Support\Servers\CacheServiceMemoryConfig;
use App\Support\Servers\CacheServicePort;
use App\Support\Servers\CacheServiceStats;
use App\Support\Servers\ServerCacheServiceHostCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceCachesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test scaffolding shared across every case in this class:
     *
     *  1. `Queue::fake()` so the Server::created hook (which dispatches
     *     `ProvisionDefaultUserSshKeysToServerJob` synchronously under
     *     `QUEUE_CONNECTION=sync`) doesn't try to SSH to a real host.
     *
     *  2. A default-stubbed `CacheServiceStats` binding so the workspace's
     *     `render()` doesn't fire a real `redis-cli INFO` call when a
     *     `ServerCacheService` row is RUNNING. Tests that care about a
     *     specific snapshot can override via `$this->mock(...)`.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->mock(CacheServiceStats::class, function ($mock): void {
            $mock->shouldReceive('snapshot')->byDefault()->andReturn([]);
            // The workspace forgets the cached stats from any state-mutating action; without
            // this the strict Mockery default surfaces "no expectations" failures.
            $mock->shouldReceive('forget')->byDefault()->andReturnNull();
        });
    }

    /**
     * @return array{0: User, 1: Server}
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

    public function test_workspace_renders_with_no_cache_service(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => false, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->assertSet('workspace_tab', 'overview')
            ->assertSee('Overview')
            ->assertSee('Redis')
            ->assertSee('Valkey')
            ->assertSee('Memcached')
            ->assertSee('KeyDB')
            ->assertSee('Dragonfly')
            ->assertSee('No cache services installed');
    }

    public function test_install_dispatches_job_and_creates_row(): void
    {
        Queue::fake();
        [$user, $server] = $this->actingOwnerWithServer();

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => false, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('installCacheService', 'redis')
            ->assertHasNoErrors()
            ->assertSet('workspace_tab', 'redis');

        Queue::assertPushed(InstallCacheServiceJob::class);
        $this->assertDatabaseHas('server_cache_services', [
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_PENDING,
            'port' => 6379,
        ]);
    }

    public function test_install_allows_memcached_alongside_a_redis_family_engine(): void
    {
        // The coexistence rule allows one redis-family engine PLUS one memcached. Different wire
        // protocols, different ports, no resource overlap — common pattern is Redis for queues
        // and Memcached for sessions. The install action must queue a job and create the row
        // without touching the existing redis row.
        Queue::fake();
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => true, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
            $mock->shouldReceive('engineUnsupportedReason')->andReturn(null);
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('installCacheService', 'memcached')
            ->assertHasNoErrors();

        Queue::assertPushed(InstallCacheServiceJob::class);
        $this->assertDatabaseHas('server_cache_services', [
            'server_id' => $server->id,
            'engine' => 'memcached',
            'status' => ServerCacheService::STATUS_PENDING,
            'port' => 11211,
        ]);
        $this->assertDatabaseHas('server_cache_services', [
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_RUNNING,
        ]);
    }

    public function test_install_refuses_second_redis_family_engine_on_same_server(): void
    {
        // Coexistence rule: at most one redis-family engine per server. With Redis already
        // installed, attempting to install Valkey must be refused before any job is queued —
        // the operator's path forward is Uninstall on Redis or use the engine-switch flow.
        Queue::fake();
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => true, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
            $mock->shouldReceive('engineUnsupportedReason')->andReturn(null);
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('installCacheService', 'valkey');

        Queue::assertNotPushed(InstallCacheServiceJob::class);
        $this->assertDatabaseMissing('server_cache_services', [
            'server_id' => $server->id,
            'engine' => 'valkey',
        ]);
    }

    public function test_install_blocks_when_another_install_is_in_flight(): void
    {
        // The new server-wide busy guard blocks even cross-engine installs while apt is
        // running on the box (dpkg lock-frontend is per-host).
        Queue::fake();
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_INSTALLING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => false, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('installCacheService', 'valkey');

        Queue::assertNotPushed(InstallCacheServiceJob::class);
        $this->assertDatabaseMissing('server_cache_services', [
            'server_id' => $server->id,
            'engine' => 'valkey',
        ]);
    }

    public function test_uninstall_dispatches_job(): void
    {
        Queue::fake();
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'memcached',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 11211,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => false, 'valkey' => false, 'memcached' => true, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('uninstallCacheService', 'memcached')
            ->assertHasNoErrors();

        Queue::assertPushed(UninstallCacheServiceJob::class);
    }

    public function test_overview_renders_connection_snippet_for_active_engine(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
            'version' => '7.2.0',
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => true, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        // Stats service runs on render — return a token snapshot so we don't hit the network.
        $this->mock(CacheServiceStats::class, function ($mock): void {
            $mock->shouldReceive('snapshot')->andReturn(['Memory used' => '1.2 MB']);
        });

        // Multi-engine Overview: connection snippet renders per engine, stats appear inline in the
        // engine's status card (no separate "live stats" header card anymore).
        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->assertSee('Redis — connection snippet')
            ->assertSee('CACHE_STORE=redis')
            ->assertSee('REDIS_HOST=127.0.0.1')
            ->assertSee('REDIS_PORT=6379')
            ->assertSee('Memory used')
            ->assertSee('1.2 MB');
    }

    public function test_overview_renders_memcached_snippet_when_active(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'memcached',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 11211,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => false, 'valkey' => false, 'memcached' => true, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $this->mock(CacheServiceStats::class, function ($mock): void {
            $mock->shouldReceive('snapshot')->andReturn([]);
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->assertSee('CACHE_STORE=memcached')
            ->assertSee('MEMCACHED_HOST=127.0.0.1')
            ->assertSee('MEMCACHED_PORT=11211')
            ->assertDontSee('REDIS_HOST');
    }

    public function test_flush_all_runs_redis_cli_for_redis_family(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => true, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });
        $this->mock(CacheServiceStats::class, function ($mock): void {
            $mock->shouldReceive('snapshot')->andReturn([]);
        });

        // Capture the inline bash so we can assert it dispatches the right CLI.
        $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
            $mock->shouldReceive('runInlineBash')
                ->once()
                ->withArgs(function ($server, $name, $cmd): bool {
                    return $name === 'cache-service:flush:redis' && str_contains($cmd, 'redis-cli -p 6379 FLUSHALL');
                })
                ->andReturn(new ProcessOutput('OK', 0, false));
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('flushCacheService', 'redis')
            ->assertHasNoErrors();
    }

    public function test_flush_all_uses_nc_for_memcached(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'memcached',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 11211,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => false, 'valkey' => false, 'memcached' => true, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });
        $this->mock(CacheServiceStats::class, function ($mock): void {
            $mock->shouldReceive('snapshot')->andReturn([]);
        });

        $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
            $mock->shouldReceive('runInlineBash')
                ->once()
                ->withArgs(function ($server, $name, $cmd): bool {
                    return $name === 'cache-service:flush:memcached'
                        && str_contains($cmd, 'flush_all')
                        && str_contains($cmd, 'nc -q 1 127.0.0.1 11211');
                })
                ->andReturn(new ProcessOutput('OK', 0, false));
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('flushCacheService', 'memcached')
            ->assertHasNoErrors();
    }

    public function test_flush_all_blocks_when_engine_not_running(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_STOPPED,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => false, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });
        $this->mock(CacheServiceStats::class, function ($mock): void {
            $mock->shouldReceive('snapshot')->andReturn([]);
        });

        // Stopped engine → no SSH call should happen at all.
        $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
            $mock->shouldNotReceive('runInlineBash');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('flushCacheService', 'redis');
    }

    public function test_flush_records_an_audit_event(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => true, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
            $mock->shouldReceive('runInlineBash')->andReturn(new ProcessOutput('OK', 0, false));
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('flushCacheService', 'redis')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('server_cache_service_audit_events', [
            'server_id' => $server->id,
            'user_id' => $user->id,
            'event' => ServerCacheServiceAuditEvent::EVENT_FLUSHED,
        ]);
    }

    public function test_load_cache_config_populates_content_property(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => true, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
            $mock->shouldReceive('runInlineBash')
                ->once()
                ->withArgs(function ($server, $name, $cmd): bool {
                    return $name === 'cache-service:config:redis'
                        && str_contains($cmd, '/etc/redis/redis.conf')
                        && str_contains($cmd, 'head -c 65536');
                })
                ->andReturn(new ProcessOutput("# redis.conf\nmaxmemory 256mb\n", 0, false));
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('workspace_tab', 'redis')
            ->set('engine_subtab', 'configure')
            ->call('loadCacheConfig')
            ->assertSet('cacheConfigPath', '/etc/redis/redis.conf')
            ->assertSet('cacheConfigError', null)
            ->assertSee('maxmemory 256mb');
    }

    public function test_set_auth_password_calls_helper_and_persists_encrypted(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $row = ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => true, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $newPassword = 'SuperSecret-ForTest-1234';
        $this->mock(CacheServiceAuth::class, function ($mock) use ($newPassword): void {
            $mock->shouldReceive('setRequirePass')
                ->once()
                ->withArgs(function ($server, $row, string $password) use ($newPassword): bool {
                    return $row->engine === 'redis' && $password === $newPassword;
                });
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('workspace_tab', 'redis')
            ->set('new_auth_password', $newPassword)
            ->call('setAuthPassword')
            ->assertHasNoErrors()
            ->assertSet('new_auth_password', '');

        // The cast on the model decrypts on read; the column itself is encrypted at rest.
        $this->assertSame($newPassword, $row->fresh()->auth_password);

        $this->assertDatabaseHas('server_cache_service_audit_events', [
            'server_id' => $server->id,
            'user_id' => $user->id,
            'event' => ServerCacheServiceAuditEvent::EVENT_AUTH_SET,
        ]);
    }

    public function test_set_auth_password_rejects_short_or_empty_value(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => true, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        // Helper must NOT be invoked — validation should bail first.
        $this->mock(CacheServiceAuth::class, function ($mock): void {
            $mock->shouldNotReceive('setRequirePass');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('workspace_tab', 'redis')
            ->set('new_auth_password', 'short')
            ->call('setAuthPassword')
            ->assertHasErrors(['new_auth_password' => 'min']);
    }

    public function test_set_auth_password_refuses_on_memcached(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'memcached',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 11211,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => false, 'valkey' => false, 'memcached' => true, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $this->mock(CacheServiceAuth::class, function ($mock): void {
            $mock->shouldNotReceive('setRequirePass');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('workspace_tab', 'memcached')
            ->set('new_auth_password', 'PerfectlyValid-1234')
            ->call('setAuthPassword');

        // Memcached has no native AUTH — we toast, no row update, no audit event.
        $this->assertDatabaseMissing('server_cache_service_audit_events', [
            'server_id' => $server->id,
            'event' => ServerCacheServiceAuditEvent::EVENT_AUTH_SET,
        ]);
    }

    public function test_clear_auth_password_calls_helper_and_nulls_column(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $row = ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
            'auth_password' => 'PreviouslySet-SecretValue-99',
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => true, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $this->mock(CacheServiceAuth::class, function ($mock): void {
            $mock->shouldReceive('clearRequirePass')->once();
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('workspace_tab', 'redis')
            ->call('clearAuthPassword')
            ->assertHasNoErrors();

        $this->assertNull($row->fresh()->auth_password);
        $this->assertDatabaseHas('server_cache_service_audit_events', [
            'server_id' => $server->id,
            'event' => ServerCacheServiceAuditEvent::EVENT_AUTH_CLEARED,
        ]);
    }

    public function test_load_cache_clients_populates_property_from_stats(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => true, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $sample = [
            ['id' => '12', 'addr' => '127.0.0.1:55012', 'name' => '', 'age' => '300', 'idle' => '5', 'db' => '0'],
            ['id' => '13', 'addr' => '127.0.0.1:55301', 'name' => 'workers', 'age' => '60', 'idle' => '0', 'db' => '0'],
        ];

        $this->mock(CacheServiceStats::class, function ($mock) use ($sample): void {
            $mock->shouldReceive('snapshot')->byDefault()->andReturn([]);
            $mock->shouldReceive('clients')->once()->andReturn($sample);
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('workspace_tab', 'redis')
            ->set('engine_subtab', 'stats')
            ->call('loadCacheClients')
            ->assertSet('cacheClientsError', null)
            ->assertSet('cacheClients', $sample)
            ->assertSee('127.0.0.1:55012')
            ->assertSee('workers');
    }

    public function test_load_cache_clients_refuses_for_memcached(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'memcached',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 11211,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => false, 'valkey' => false, 'memcached' => true, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        // Stats service should NOT be asked for clients on memcached.
        $this->mock(CacheServiceStats::class, function ($mock): void {
            $mock->shouldReceive('snapshot')->byDefault()->andReturn([]);
            $mock->shouldNotReceive('clients');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('workspace_tab', 'memcached')
            ->call('loadCacheClients')
            ->assertSet('cacheClients', null);
    }

    public function test_save_cache_config_calls_writer_and_audits(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => true, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $newConfig = "# new redis.conf\nbind 127.0.0.1\nmaxmemory 512mb\n";
        $this->mock(CacheServiceConfigWriter::class, function ($mock) use ($newConfig): void {
            $mock->shouldReceive('write')
                ->once()
                ->withArgs(function ($server, $row, string $content) use ($newConfig): bool {
                    return $row->engine === 'redis' && $content === $newConfig;
                });
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('workspace_tab', 'redis')
            ->set('cacheConfigDraft', $newConfig)
            ->set('cacheConfigEditing', true)
            ->call('saveCacheConfig')
            ->assertHasNoErrors()
            ->assertSet('cacheConfigEditing', false)
            ->assertSet('cacheConfigDraft', '')
            ->assertSet('cacheConfigContent', $newConfig);

        $this->assertDatabaseHas('server_cache_service_audit_events', [
            'server_id' => $server->id,
            'user_id' => $user->id,
            'event' => ServerCacheServiceAuditEvent::EVENT_CONFIG_EDITED,
        ]);
    }

    public function test_save_cache_config_rejects_oversize_content(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => true, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        // Writer must not be called when validation fails.
        $this->mock(CacheServiceConfigWriter::class, function ($mock): void {
            $mock->shouldNotReceive('write');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('workspace_tab', 'redis')
            ->set('cacheConfigDraft', str_repeat('a', 262145))
            ->call('saveCacheConfig')
            ->assertHasErrors(['cacheConfigDraft']);
    }

    public function test_cancel_editing_cache_config_clears_draft(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => true, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('cacheConfigEditing', true)
            ->set('cacheConfigDraft', 'unsaved changes here')
            ->call('cancelEditingCacheConfig')
            ->assertSet('cacheConfigEditing', false)
            ->assertSet('cacheConfigDraft', '');
    }

    public function test_overview_renders_all_snippet_variants_for_redis(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => true, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->assertSee('CACHE_STORE=redis')                                  // Laravel .env
            ->assertSee('import Redis from', escape: false)                   // Node.js (apostrophes get HTML-escaped, escape: false sidesteps)
            ->assertSee('ioredis', escape: false)
            ->assertSee('import redis')                                       // Python
            ->assertSee('image: your-app:latest')                             // Docker compose
            ->assertDontSee('memjs');                                          // memcached snippet must not leak in
    }

    public function test_load_cache_memory_settings_populates_form(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => true, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $this->mock(CacheServiceMemoryConfig::class, function ($mock): void {
            $mock->shouldReceive('read')
                ->once()
                ->andReturn(['maxmemory' => '512mb', 'maxmemory_policy' => 'allkeys-lru']);
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('workspace_tab', 'redis')
            ->call('loadCacheMemorySettings')
            ->assertSet('cacheMemoryLoaded', true)
            ->assertSet('cache_maxmemory', '512mb')
            ->assertSet('cache_maxmemory_policy', 'allkeys-lru');
    }

    public function test_save_cache_memory_settings_calls_writer_and_audits(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => true, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $this->mock(CacheServiceMemoryConfig::class, function ($mock): void {
            $mock->shouldReceive('write')
                ->once()
                ->withArgs(function ($server, $row, ?string $maxmem, ?string $policy): bool {
                    return $maxmem === '256mb' && $policy === 'allkeys-lfu';
                });
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('workspace_tab', 'redis')
            ->set('cache_maxmemory', '256MB')
            ->set('cache_maxmemory_policy', 'allkeys-lfu')
            ->call('saveCacheMemorySettings')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('server_cache_service_audit_events', [
            'server_id' => $server->id,
            'user_id' => $user->id,
            'event' => ServerCacheServiceAuditEvent::EVENT_MEMORY_UPDATED,
        ]);
    }

    public function test_save_cache_memory_settings_rejects_bad_maxmemory(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => true, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $this->mock(CacheServiceMemoryConfig::class, function ($mock): void {
            $mock->shouldNotReceive('write');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('workspace_tab', 'redis')
            ->set('cache_maxmemory', 'bananas')
            ->call('saveCacheMemorySettings')
            ->assertHasErrors(['cache_maxmemory']);
    }

    public function test_url_drives_active_tab_and_unknowns_fall_back(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => false, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        // Valid value via property set (mirrors how #[Url] hydrates the property after mount).
        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('workspace_tab', 'redis')
            ->assertSet('workspace_tab', 'redis');

        // A bogus URL value snaps back to overview during render's allowlist guard.
        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('workspace_tab', 'garbage')
            ->assertSet('workspace_tab', 'overview');
    }

    public function test_change_cache_port_invokes_helper_and_persists_new_port(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        $row = ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'valkey',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => false, 'valkey' => true, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $this->mock(CacheServicePort::class, function ($mock): void {
            $mock->shouldReceive('changePort')
                ->once()
                ->withArgs(function ($srv, $row, int $newPort): bool {
                    return $row->engine === 'valkey' && $newPort === 6390;
                });
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('workspace_tab', 'valkey')
            ->set('new_port', 6390)
            ->call('changeCachePort')
            ->assertHasNoErrors()
            ->assertSet('new_port', null);

        $this->assertSame(6390, $row->fresh()->port);

        $this->assertDatabaseHas('server_cache_service_audit_events', [
            'server_id' => $server->id,
            'user_id' => $user->id,
            'event' => ServerCacheServiceAuditEvent::EVENT_PORT_CHANGED,
        ]);
    }

    public function test_change_cache_port_validates_range(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'valkey',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => false, 'valkey' => true, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        // Helper must NOT be invoked — validation should bail first.
        $this->mock(CacheServicePort::class, function ($mock): void {
            $mock->shouldNotReceive('changePort');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('workspace_tab', 'valkey')
            ->set('new_port', 80)
            ->call('changeCachePort')
            ->assertHasErrors(['new_port' => 'min']);
    }

    public function test_change_cache_port_rejects_collision_with_another_engine(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6380,
        ]);
        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'valkey',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => true, 'valkey' => true, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $this->mock(CacheServicePort::class, function ($mock): void {
            $mock->shouldNotReceive('changePort');
        });

        // Try to move Valkey onto Redis's port — must be rejected before SSH.
        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('workspace_tab', 'valkey')
            ->set('new_port', 6380)
            ->call('changeCachePort')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('server_cache_service_audit_events', [
            'server_id' => $server->id,
            'event' => ServerCacheServiceAuditEvent::EVENT_PORT_CHANGED,
        ]);
    }

    public function test_change_cache_port_rejects_no_op(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'valkey',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => false, 'valkey' => true, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $this->mock(CacheServicePort::class, function ($mock): void {
            $mock->shouldNotReceive('changePort');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('workspace_tab', 'valkey')
            ->set('new_port', 6379)
            ->call('changeCachePort');

        $this->assertDatabaseMissing('server_cache_service_audit_events', [
            'server_id' => $server->id,
            'event' => ServerCacheServiceAuditEvent::EVENT_PORT_CHANGED,
        ]);
    }

    public function test_show_cache_instance_status_runs_systemctl_for_default_instance(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => true, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
            $mock->shouldReceive('probeInstance')->andReturn(true);
        });

        $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
            $mock->shouldReceive('runInlineBash')
                ->once()
                ->withArgs(function ($server, $name, $cmd): bool {
                    return $name === 'cache-service:status:redis:default'
                        && str_contains($cmd, 'systemctl status')
                        && str_contains($cmd, "'redis-server'");
                })
                ->andReturn(new \App\Modules\TaskRunner\ProcessOutput("● redis-server.service — active (running)\n", 0, false));
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('workspace_tab', 'redis')
            ->call('showCacheInstanceStatus', 'redis')
            ->assertHasNoErrors()
            ->assertSet('showCacheStatusModal', true)
            ->assertSet('cacheStatusModalEngine', 'redis')
            ->assertSet('cacheStatusModalInstance', 'default')
            ->assertSet('cacheStatusModalUnit', 'redis-server')
            ->assertSet('cacheStatusModalView', 'status')
            ->assertSet('cacheStatusModalLoading', false)
            ->assertSee('active (running)');
    }

    public function test_show_cache_instance_logs_runs_journalctl_for_named_instance(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_RUNNING,
            'name' => 'queue',
            'port' => 6380,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => true, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
            $mock->shouldReceive('probeInstance')->andReturn(true);
        });

        $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
            $mock->shouldReceive('runInlineBash')
                ->once()
                ->withArgs(function ($server, $name, $cmd): bool {
                    return $name === 'cache-service:logs:redis:queue'
                        && str_contains($cmd, 'journalctl --no-pager --output=short-iso')
                        && str_contains($cmd, "'redis-server@queue'")
                        && str_contains($cmd, '-n 200');
                })
                ->andReturn(new \App\Modules\TaskRunner\ProcessOutput("2026-05-11T10:00:00+0000 redis-server: Ready to accept connections\n", 0, false));
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('workspace_tab', 'redis')
            ->set('active_instance', 'queue')
            ->call('showCacheInstanceLogs', 'redis')
            ->assertHasNoErrors()
            ->assertSet('showCacheStatusModal', true)
            ->assertSet('cacheStatusModalInstance', 'queue')
            ->assertSet('cacheStatusModalUnit', 'redis-server@queue')
            ->assertSet('cacheStatusModalView', 'logs')
            ->assertSee('Ready to accept connections');
    }

    public function test_set_cache_status_modal_view_reprobes_with_logs_script(): void
    {
        [$user, $server] = $this->actingOwnerWithServer();

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn(['redis' => true, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
            $mock->shouldReceive('probeInstance')->andReturn(true);
        });

        // First call (Status open) returns systemctl output; second call
        // (after the view switches to Logs) returns journalctl output.
        $this->mock(ExecuteRemoteTaskOnServer::class, function ($mock): void {
            $mock->shouldReceive('runInlineBash')
                ->once()
                ->withArgs(fn ($server, $name, $cmd): bool => str_contains($cmd, 'systemctl status'))
                ->andReturn(new \App\Modules\TaskRunner\ProcessOutput('initial status output', 0, false));

            $mock->shouldReceive('runInlineBash')
                ->once()
                ->withArgs(fn ($server, $name, $cmd): bool => str_contains($cmd, 'journalctl --no-pager --output=short-iso'))
                ->andReturn(new \App\Modules\TaskRunner\ProcessOutput('switched logs output', 0, false));
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->set('workspace_tab', 'redis')
            ->call('showCacheInstanceStatus', 'redis')
            ->assertSet('cacheStatusModalView', 'status')
            ->assertSee('initial status output')
            ->call('setCacheStatusModalView', 'logs')
            ->assertSet('cacheStatusModalView', 'logs')
            ->assertSee('switched logs output');
    }
}
