<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Servers\WorkspaceCaches;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerCacheServiceAuditEvent;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Support\Servers\CacheServiceCli;
use App\Support\Servers\CacheServiceStats;
use App\Support\Servers\ServerCacheServiceHostCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;
use Tests\TestCase;

class WorkspaceCachesReplTest extends TestCase
{
    use RefreshDatabase;
    use WithFeatures;

    protected array $features = ['workspace.caches'];

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
    protected function actingOwnerWithRedis(): array
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

        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        return [$user, $server];
    }

    public function test_read_only_command_runs_and_appears_in_history(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        $this->mock(CacheServiceCli::class, function ($mock): void {
            $mock->shouldReceive('execute')
                ->once()
                ->withArgs(fn ($srv, $row, $cmd) => $cmd === 'INFO server')
                ->andReturn(new ProcessOutput("# Server\nredis_version:7.2.0\n", 0, false));
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->set('replInput', 'INFO server')
            ->call('runReplCommand')
            ->assertHasNoErrors()
            ->assertSet('replInput', '')
            ->tap(function ($component): void {
                $history = $component->get('replHistory');
                $this->assertCount(1, $history);
                $this->assertSame('INFO server', $history[0]['cmd']);
                $this->assertStringContainsString('redis_version:7.2.0', $history[0]['output']);
                $this->assertSame('sent', $history[0]['kind']);
            });

        $this->assertDatabaseHas('server_cache_service_audit_events', [
            'server_id' => $server->id,
            'event' => ServerCacheServiceAuditEvent::EVENT_REPL_EXECUTED,
        ]);
    }

    public function test_mutating_command_is_denied_when_locked(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        // Cli must NOT be invoked for a denied command.
        $this->mock(CacheServiceCli::class, function ($mock): void {
            $mock->shouldNotReceive('execute');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->set('replInput', 'SET foo bar')
            ->call('runReplCommand')
            ->assertHasNoErrors()
            ->tap(function ($component): void {
                $history = $component->get('replHistory');
                $this->assertCount(1, $history);
                $this->assertSame('error', $history[0]['kind']);
                $this->assertStringContainsString('Read-only', $history[0]['output']);
            });

        $this->assertDatabaseHas('server_cache_service_audit_events', [
            'server_id' => $server->id,
            'event' => ServerCacheServiceAuditEvent::EVENT_REPL_DENIED,
        ]);
    }

    public function test_mutating_command_runs_when_unlocked(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        $this->mock(CacheServiceCli::class, function ($mock): void {
            $mock->shouldReceive('execute')
                ->once()
                ->withArgs(fn ($srv, $row, $cmd) => $cmd === 'SET foo bar')
                ->andReturn(new ProcessOutput("OK\n", 0, false));
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->set('replUnlocked', true)
            ->set('replInput', 'SET foo bar')
            ->call('runReplCommand')
            ->assertHasNoErrors()
            ->tap(function ($component): void {
                $history = $component->get('replHistory');
                $this->assertCount(1, $history);
                $this->assertSame('sent', $history[0]['kind']);
                $this->assertSame(0, $history[0]['exit_code']);
            });

        $this->assertDatabaseHas('server_cache_service_audit_events', [
            'server_id' => $server->id,
            'event' => ServerCacheServiceAuditEvent::EVENT_REPL_EXECUTED,
        ]);
        $event = ServerCacheServiceAuditEvent::query()
            ->where('server_id', $server->id)
            ->where('event', ServerCacheServiceAuditEvent::EVENT_REPL_EXECUTED)
            ->latest()
            ->firstOrFail();
        $this->assertSame('SET', $event->meta['verb']);
        $this->assertTrue((bool) $event->meta['mutating']);
    }

    public function test_blocked_command_is_blocked_even_when_unlocked(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        $this->mock(CacheServiceCli::class, function ($mock): void {
            $mock->shouldNotReceive('execute');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->set('replUnlocked', true)
            ->set('replInput', 'SHUTDOWN NOSAVE')
            ->call('runReplCommand')
            ->assertHasNoErrors()
            ->tap(function ($component): void {
                $history = $component->get('replHistory');
                $this->assertCount(1, $history);
                $this->assertSame('error', $history[0]['kind']);
                $this->assertStringContainsString('Blocked', $history[0]['output']);
            });

        $this->assertDatabaseHas('server_cache_service_audit_events', [
            'server_id' => $server->id,
            'event' => ServerCacheServiceAuditEvent::EVENT_REPL_BLOCKED,
        ]);
    }

    public function test_repl_rejects_memcached_engine(): void
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
        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'memcached',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 11211,
        ]);

        $this->mock(ServerCacheServiceHostCapabilities::class, function ($mock): void {
            $mock->shouldReceive('forServer')->andReturn([
                'redis' => false, 'valkey' => false, 'memcached' => true,
                'keydb' => false, 'dragonfly' => false,
            ]);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $this->mock(CacheServiceCli::class, function ($mock): void {
            $mock->shouldNotReceive('execute');
        });

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'memcached')
            ->set('replInput', 'PING')
            ->call('runReplCommand')
            ->assertHasNoErrors()
            ->tap(function ($component): void {
                $this->assertEmpty($component->get('replHistory'));
            });

        $this->assertDatabaseMissing('server_cache_service_audit_events', [
            'server_id' => $server->id,
            'event' => ServerCacheServiceAuditEvent::EVENT_REPL_EXECUTED,
        ]);
    }

    public function test_repl_history_caps_at_fifty_entries(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        $this->mock(CacheServiceCli::class, function ($mock): void {
            $mock->shouldReceive('execute')
                ->andReturn(new ProcessOutput("PONG\n", 0, false));
        });

        $component = Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis');

        for ($i = 0; $i < WorkspaceCaches::REPL_HISTORY_LIMIT + 5; $i++) {
            $component->set('replInput', 'PING')->call('runReplCommand');
        }

        $history = $component->get('replHistory');
        $this->assertCount(WorkspaceCaches::REPL_HISTORY_LIMIT, $history);
    }

    public function test_toggle_repl_unlock_writes_audit_event(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->call('toggleReplUnlock')
            ->assertHasNoErrors()
            ->assertSet('replUnlocked', true);

        $this->assertDatabaseHas('server_cache_service_audit_events', [
            'server_id' => $server->id,
            'event' => ServerCacheServiceAuditEvent::EVENT_REPL_UNLOCKED,
        ]);
    }

    public function test_clear_repl_history_resets_buffer(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->set('replHistory', [['ts' => 'now', 'cmd' => 'PING', 'output' => 'PONG', 'exit_code' => 0, 'kind' => 'sent']])
            ->set('replInput', 'GET foo')
            ->call('clearReplHistory')
            ->assertSet('replHistory', [])
            ->assertSet('replInput', '');
    }
}
