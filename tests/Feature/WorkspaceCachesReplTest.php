<?php

declare(strict_types=1);

namespace Tests\Feature\WorkspaceCachesReplTest;

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

uses(RefreshDatabase::class);

usesFeatures('workspace.caches');

beforeEach(function () {
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
});
/**
 * @return array{User, Server}
 */
function actingOwnerWithRedis(): array
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
test('read only command runs and appears in history', function () {
    [$user, $server] = actingOwnerWithRedis();

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
            expect($history)->toHaveCount(1);
            expect($history[0]['cmd'])->toBe('INFO server');
            $this->assertStringContainsString('redis_version:7.2.0', $history[0]['output']);
            expect($history[0]['kind'])->toBe('sent');
        });

    $this->assertDatabaseHas('server_cache_service_audit_events', [
        'server_id' => $server->id,
        'event' => ServerCacheServiceAuditEvent::EVENT_REPL_EXECUTED,
    ]);
});
test('mutating command is denied when locked', function () {
    [$user, $server] = actingOwnerWithRedis();

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
            expect($history)->toHaveCount(1);
            expect($history[0]['kind'])->toBe('error');
            $this->assertStringContainsString('Read-only', $history[0]['output']);
        });

    $this->assertDatabaseHas('server_cache_service_audit_events', [
        'server_id' => $server->id,
        'event' => ServerCacheServiceAuditEvent::EVENT_REPL_DENIED,
    ]);
});
test('mutating command runs when unlocked', function () {
    [$user, $server] = actingOwnerWithRedis();

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
            expect($history)->toHaveCount(1);
            expect($history[0]['kind'])->toBe('sent');
            expect($history[0]['exit_code'])->toBe(0);
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
    expect($event->meta['verb'])->toBe('SET');
    expect((bool) $event->meta['mutating'])->toBeTrue();
});
test('blocked command is blocked even when unlocked', function () {
    [$user, $server] = actingOwnerWithRedis();

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
            expect($history)->toHaveCount(1);
            expect($history[0]['kind'])->toBe('error');
            $this->assertStringContainsString('Blocked', $history[0]['output']);
        });

    $this->assertDatabaseHas('server_cache_service_audit_events', [
        'server_id' => $server->id,
        'event' => ServerCacheServiceAuditEvent::EVENT_REPL_BLOCKED,
    ]);
});
test('repl rejects memcached engine', function () {
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
            expect($component->get('replHistory'))->toBeEmpty();
        });

    $this->assertDatabaseMissing('server_cache_service_audit_events', [
        'server_id' => $server->id,
        'event' => ServerCacheServiceAuditEvent::EVENT_REPL_EXECUTED,
    ]);
});
test('repl history caps at fifty entries', function () {
    [$user, $server] = actingOwnerWithRedis();

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
    expect($history)->toHaveCount(WorkspaceCaches::REPL_HISTORY_LIMIT);
});
test('toggle repl unlock writes audit event', function () {
    [$user, $server] = actingOwnerWithRedis();

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
});
test('clear repl history resets buffer', function () {
    [$user, $server] = actingOwnerWithRedis();

    Livewire::actingAs($user)
        ->test(WorkspaceCaches::class, ['server' => $server])
        ->call('setWorkspaceTab', 'redis')
        ->set('replHistory', [['ts' => 'now', 'cmd' => 'PING', 'output' => 'PONG', 'exit_code' => 0, 'kind' => 'sent']])
        ->set('replInput', 'GET foo')
        ->call('clearReplHistory')
        ->assertSet('replHistory', [])
        ->assertSet('replInput', '');
});
