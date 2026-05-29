<?php

declare(strict_types=1);

namespace Tests\Feature\WorkspaceCachesTest;

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
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

usesFeatures('workspace.caches');

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
beforeEach(function () {
    Queue::fake();

    $this->mock(CacheServiceStats::class, function ($mock): void {
        $mock->shouldReceive('snapshot')->byDefault()->andReturn([]);
        // The workspace forgets the cached stats from any state-mutating action; without
        // this the strict Mockery default surfaces "no expectations" failures.
        $mock->shouldReceive('forget')->byDefault()->andReturnNull();
    });
});
/**
 * @return array{0: User, 1: Server}
 */
function actingOwnerWithServer(): array
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
test('workspace renders with no cache service', function () {
    [$user, $server] = actingOwnerWithServer();

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
});
test('install dispatches job and creates row', function () {
    Queue::fake();
    [$user, $server] = actingOwnerWithServer();

    $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
        $mock->shouldReceive('forServer')->andReturn(['redis' => false, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
        $mock->shouldReceive('engineUnsupportedReason')->zeroOrMoreTimes()->andReturn(null);
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
});
test('install allows memcached alongside a redis family engine', function () {
    // The coexistence rule allows one redis-family engine PLUS one memcached. Different wire
    // protocols, different ports, no resource overlap — common pattern is Redis for queues
    // and Memcached for sessions. The install action must queue a job and create the row
    // without touching the existing redis row.
    Queue::fake();
    [$user, $server] = actingOwnerWithServer();

    // Memcached is gated behind cache.memcached (coming soon by default); enable
    // it platform-wide via config so this test exercises the coexistence path, not the gate.
    config(['features.cache.memcached' => true]);
    Feature::flushCache();

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
});
test('install refuses second redis family engine on same server', function () {
    // Coexistence rule: at most one redis-family engine per server. With Redis already
    // installed, attempting to install Valkey must be refused before any job is queued —
    // the operator's path forward is Uninstall on Redis or use the engine-switch flow.
    Queue::fake();
    [$user, $server] = actingOwnerWithServer();

    // Enable Valkey so the refusal under test is the redis-family coexistence
    // rule, not the coming-soon gate.
    config(['features.cache.valkey' => true]);
    Feature::flushCache();

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
});
test('install refuses a coming soon engine before queueing', function () {
    // Default state: Valkey is gated behind cache.valkey (coming soon). The
    // install action must refuse without creating a row or queueing a job.
    Queue::fake();
    [$user, $server] = actingOwnerWithServer();

    $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
        $mock->shouldReceive('forServer')->andReturn(['redis' => false, 'valkey' => false, 'memcached' => false, 'keydb' => false, 'dragonfly' => false]);
        $mock->shouldReceive('forget')->zeroOrMoreTimes();
        $mock->shouldReceive('engineUnsupportedReason')->zeroOrMoreTimes()->andReturn(null);
    });

    Livewire::actingAs($user)
        ->test(WorkspaceCaches::class, ['server' => $server])
        ->call('installCacheService', 'valkey');

    Queue::assertNotPushed(InstallCacheServiceJob::class);
    $this->assertDatabaseMissing('server_cache_services', [
        'server_id' => $server->id,
        'engine' => 'valkey',
    ]);
});
test('install blocks when another install is in flight', function () {
    // The new server-wide busy guard blocks even cross-engine installs while apt is
    // running on the box (dpkg lock-frontend is per-host).
    Queue::fake();
    [$user, $server] = actingOwnerWithServer();

    // Enable Valkey so the in-flight busy guard is what blocks the install,
    // not the coming-soon gate.
    config(['features.cache.valkey' => true]);
    Feature::flushCache();

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
});
test('uninstall dispatches job', function () {
    Queue::fake();
    [$user, $server] = actingOwnerWithServer();

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
});
test('overview renders connection snippet for active engine', function () {
    [$user, $server] = actingOwnerWithServer();

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
});
test('overview renders memcached snippet when active', function () {
    [$user, $server] = actingOwnerWithServer();

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
});
test('flush all runs redis cli for redis family', function () {
    [$user, $server] = actingOwnerWithServer();

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
});
test('flush all uses nc for memcached', function () {
    [$user, $server] = actingOwnerWithServer();

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
});
test('flush all blocks when engine not running', function () {
    [$user, $server] = actingOwnerWithServer();

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
});
test('flush records an audit event', function () {
    [$user, $server] = actingOwnerWithServer();

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
});
test('load cache config populates content property', function () {
    [$user, $server] = actingOwnerWithServer();

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
});
test('set auth password calls helper and persists encrypted', function () {
    [$user, $server] = actingOwnerWithServer();

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
    expect($row->fresh()->auth_password)->toBe($newPassword);

    $this->assertDatabaseHas('server_cache_service_audit_events', [
        'server_id' => $server->id,
        'user_id' => $user->id,
        'event' => ServerCacheServiceAuditEvent::EVENT_AUTH_SET,
    ]);
});
test('set auth password rejects short or empty value', function () {
    [$user, $server] = actingOwnerWithServer();

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
});
test('set auth password refuses on memcached', function () {
    [$user, $server] = actingOwnerWithServer();

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
});
test('clear auth password calls helper and nulls column', function () {
    [$user, $server] = actingOwnerWithServer();

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

    expect($row->fresh()->auth_password)->toBeNull();
    $this->assertDatabaseHas('server_cache_service_audit_events', [
        'server_id' => $server->id,
        'event' => ServerCacheServiceAuditEvent::EVENT_AUTH_CLEARED,
    ]);
});
test('load cache clients populates property from stats', function () {
    [$user, $server] = actingOwnerWithServer();

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
});
test('load cache clients refuses for memcached', function () {
    [$user, $server] = actingOwnerWithServer();

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
});
test('save cache config calls writer and audits', function () {
    [$user, $server] = actingOwnerWithServer();

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
});
test('save cache config rejects oversize content', function () {
    [$user, $server] = actingOwnerWithServer();

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
});
test('cancel editing cache config clears draft', function () {
    [$user, $server] = actingOwnerWithServer();

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
});
test('overview renders all snippet variants for redis', function () {
    [$user, $server] = actingOwnerWithServer();

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
        ->assertDontSee('memjs');
    // memcached snippet must not leak in
});
test('load cache memory settings populates form', function () {
    [$user, $server] = actingOwnerWithServer();

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
});
test('save cache memory settings calls writer and audits', function () {
    [$user, $server] = actingOwnerWithServer();

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
});
test('save cache memory settings rejects bad maxmemory', function () {
    [$user, $server] = actingOwnerWithServer();

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
});
test('url drives active tab and unknowns fall back', function () {
    [$user, $server] = actingOwnerWithServer();

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
});
test('change cache port invokes helper and persists new port', function () {
    [$user, $server] = actingOwnerWithServer();

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

    expect($row->fresh()->port)->toBe(6390);

    $this->assertDatabaseHas('server_cache_service_audit_events', [
        'server_id' => $server->id,
        'user_id' => $user->id,
        'event' => ServerCacheServiceAuditEvent::EVENT_PORT_CHANGED,
    ]);
});
test('change cache port validates range', function () {
    [$user, $server] = actingOwnerWithServer();

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
});
test('change cache port rejects collision with another engine', function () {
    [$user, $server] = actingOwnerWithServer();

    // Post-collapse, redis-family engines are mutually exclusive on a server (one
    // redis_OR_valkey_OR_keydb_OR_dragonfly row per server). Pair memcached with valkey
    // — those two CAN coexist by design — and test the port-collision rejection between
    // them. Memcached default port is 11211, but we put valkey there to force the conflict.
    ServerCacheService::query()->create([
        'server_id' => $server->id,
        'engine' => 'memcached',
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
        $mock->shouldReceive('forServer')->andReturn(['redis' => false, 'valkey' => true, 'memcached' => true, 'keydb' => false, 'dragonfly' => false]);
        $mock->shouldReceive('forget')->zeroOrMoreTimes();
    });

    $this->mock(CacheServicePort::class, function ($mock): void {
        $mock->shouldNotReceive('changePort');
    });

    // Try to move Valkey onto Memcached's port — must be rejected before SSH.
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
});
test('change cache port rejects no op', function () {
    [$user, $server] = actingOwnerWithServer();

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
});
test('show cache instance status runs systemctl for default instance', function () {
    [$user, $server] = actingOwnerWithServer();

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
            ->andReturn(new ProcessOutput("● redis-server.service — active (running)\n", 0, false));
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
});
test('show cache instance logs runs journalctl for default instance', function () {
    // Post-collapse there's only one instance per engine per server (`name='default'`)
    // and the systemd unit is the non-templated form (e.g. `redis-server`, not
    // `redis-server@queue`). This test verifies the logs modal targets that unit.
    [$user, $server] = actingOwnerWithServer();

    ServerCacheService::query()->create([
        'server_id' => $server->id,
        'engine' => 'redis',
        'status' => ServerCacheService::STATUS_RUNNING,
        'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
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
                return str_starts_with($name, 'cache-service:logs:redis:')
                    && str_contains($cmd, 'journalctl --no-pager --output=short-iso')
                    && str_contains($cmd, "'redis-server'")
                    && str_contains($cmd, '-n 200');
            })
            ->andReturn(new ProcessOutput("2026-05-11T10:00:00+0000 redis-server: Ready to accept connections\n", 0, false));
    });

    Livewire::actingAs($user)
        ->test(WorkspaceCaches::class, ['server' => $server])
        ->set('workspace_tab', 'redis')
        ->call('showCacheInstanceLogs', 'redis')
        ->assertHasNoErrors()
        ->assertSet('showCacheStatusModal', true)
        ->assertSet('cacheStatusModalUnit', 'redis-server')
        ->assertSet('cacheStatusModalView', 'logs')
        ->assertSee('Ready to accept connections');
});
test('set cache status modal view reprobes with logs script', function () {
    [$user, $server] = actingOwnerWithServer();

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
            ->andReturn(new ProcessOutput('initial status output', 0, false));

        $mock->shouldReceive('runInlineBash')
            ->once()
            ->withArgs(fn ($server, $name, $cmd): bool => str_contains($cmd, 'journalctl --no-pager --output=short-iso'))
            ->andReturn(new ProcessOutput('switched logs output', 0, false));
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
});
