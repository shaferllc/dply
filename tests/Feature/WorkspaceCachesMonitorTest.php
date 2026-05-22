<?php

declare(strict_types=1);

namespace Tests\Feature\WorkspaceCachesMonitorTest;

use App\Jobs\TailCacheServiceMonitorJob;
use App\Livewire\Servers\WorkspaceCaches;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\User;
use App\Support\Servers\CacheServiceStats;
use App\Support\Servers\ServerCacheServiceHostCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);

uses(WithFeatures::class);

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
 * @return array{User, Server, ServerCacheService}
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

    $row = ServerCacheService::query()->create([
        'server_id' => $server->id,
        'engine' => 'redis',
        'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
        'status' => ServerCacheService::STATUS_RUNNING,
        'port' => 6379,
    ]);

    return [$user, $server, $row];
}
test('start monitor does not require unlock', function () {
    // MONITOR is read-only — the bounded window picker (5/10/30 s) plus the explainer
    // already cover the CPU-cost caveat, so we don't gate it on the REPL unlock toggle.
    [$user, $server, $row] = actingOwnerWithRedis();

    $component = Livewire::actingAs($user)
        ->test(WorkspaceCaches::class, ['server' => $server])
        ->call('setWorkspaceTab', 'redis')
        ->assertSet('replUnlocked', false)
        ->call('startMonitor', 10)
        ->assertHasNoErrors();

    expect($component->get('monitorRunId'))->not->toBeEmpty('Run id must be set even without unlock.');
    Queue::assertPushed(
        TailCacheServiceMonitorJob::class,
        fn ($job) => $job->serverId === $server->id && $job->cacheServiceId === $row->id,
    );
});
test('start monitor dispatches job and sets run id', function () {
    [$user, $server, $row] = actingOwnerWithRedis();

    $component = Livewire::actingAs($user)
        ->test(WorkspaceCaches::class, ['server' => $server])
        ->call('setWorkspaceTab', 'redis')
        ->set('replUnlocked', true)
        ->call('startMonitor', 10)
        ->assertHasNoErrors();

    expect($component->get('monitorRunId'))->not->toBeEmpty('Run id must be set after start.');
    expect($component->get('monitorDurationSeconds'))->toBe(10);
    expect($component->get('monitorPayload.status'))->toBe('queued');

    Queue::assertPushed(
        TailCacheServiceMonitorJob::class,
        fn ($job) => $job->serverId === $server->id
            && $job->cacheServiceId === $row->id
            && $job->durationSeconds === 10,
    );
});
test('start monitor caps duration at thirty seconds', function () {
    [$user, $server] = actingOwnerWithRedis();

    Livewire::actingAs($user)
        ->test(WorkspaceCaches::class, ['server' => $server])
        ->call('setWorkspaceTab', 'redis')
        ->set('replUnlocked', true)
        ->call('startMonitor', 60)
        ->assertSet('monitorDurationSeconds', 30);
});
test('start monitor floors duration at minimum', function () {
    [$user, $server] = actingOwnerWithRedis();

    Livewire::actingAs($user)
        ->test(WorkspaceCaches::class, ['server' => $server])
        ->call('setWorkspaceTab', 'redis')
        ->set('replUnlocked', true)
        ->call('startMonitor', 1)
        ->assertSet('monitorDurationSeconds', TailCacheServiceMonitorJob::MIN_DURATION);
});
test('start monitor rejects when already running', function () {
    [$user, $server] = actingOwnerWithRedis();

    $component = Livewire::actingAs($user)
        ->test(WorkspaceCaches::class, ['server' => $server])
        ->call('setWorkspaceTab', 'redis')
        ->set('replUnlocked', true)
        ->call('startMonitor', 10);

    $firstRunId = $component->get('monitorRunId');
    expect($firstRunId)->not->toBeEmpty();

    // Second call while still running should be rejected — same run id stays.
    $component->call('startMonitor', 10);
    expect($component->get('monitorRunId'))->toBe($firstRunId);

    Queue::assertPushed(TailCacheServiceMonitorJob::class, 1);
});
test('poll picks up cache buffer', function () {
    [$user, $server] = actingOwnerWithRedis();

    $component = Livewire::actingAs($user)
        ->test(WorkspaceCaches::class, ['server' => $server])
        ->call('setWorkspaceTab', 'redis')
        ->set('replUnlocked', true)
        ->call('startMonitor', 10);

    $runId = $component->get('monitorRunId');

    Cache::put(TailCacheServiceMonitorJob::cacheKey($runId), [
        'status' => 'running',
        'lines' => ['1700000000.123 [0 127.0.0.1:55012] "GET" "foo"'],
        'started_at' => time(),
        'duration_seconds' => 10,
        'error' => null,
    ], 60);

    $component->call('pollMonitorOutput')
        ->assertSet('monitorPayload.status', 'running')
        ->tap(function ($c): void {
            expect($c->get('monitorPayload.lines'))->toBe(['1700000000.123 [0 127.0.0.1:55012] "GET" "foo"']);
        });

    // Run id stays set while status is running.
    expect($component->get('monitorRunId'))->toBe($runId);
});
test('poll clears run id on completion', function () {
    [$user, $server] = actingOwnerWithRedis();

    $component = Livewire::actingAs($user)
        ->test(WorkspaceCaches::class, ['server' => $server])
        ->call('setWorkspaceTab', 'redis')
        ->set('replUnlocked', true)
        ->call('startMonitor', 10);

    $runId = $component->get('monitorRunId');
    Cache::put(TailCacheServiceMonitorJob::cacheKey($runId), [
        'status' => 'completed',
        'lines' => ['line one', 'line two'],
        'started_at' => time(),
        'duration_seconds' => 10,
        'error' => null,
    ], 60);

    $component->call('pollMonitorOutput')
        ->assertSet('monitorRunId', '')
        ->assertSet('monitorPayload.status', 'completed');

    // Buffer remains visible after completion so the operator can scroll it.
    expect($component->get('monitorPayload.lines'))->toHaveCount(2);
});
test('clear monitor output resets state', function () {
    [$user, $server] = actingOwnerWithRedis();

    Livewire::actingAs($user)
        ->test(WorkspaceCaches::class, ['server' => $server])
        ->call('setWorkspaceTab', 'redis')
        ->set('replUnlocked', true)
        ->call('startMonitor', 10)
        ->call('clearMonitorOutput')
        ->assertSet('monitorRunId', '')
        ->assertSet('monitorPayload', null);
});
test('on chunk appends lines to payload', function () {
    [$user, $server] = actingOwnerWithRedis();

    $component = Livewire::actingAs($user)
        ->test(WorkspaceCaches::class, ['server' => $server])
        ->call('setWorkspaceTab', 'redis')
        ->set('replUnlocked', true)
        ->call('startMonitor', 10);

    $runId = $component->get('monitorRunId');

    $component->call('onMonitorChunk', $runId, "1700000000.111 [0 client] \"GET\" \"a\"\n1700000000.112 [0 client] \"GET\" \"b\"\n");

    $payload = $component->get('monitorPayload');
    expect($payload['status'])->toBe('running');
    expect($payload['lines'])->toBe(['1700000000.111 [0 client] "GET" "a"', '1700000000.112 [0 client] "GET" "b"']);
});
test('on chunk drops events for other run ids', function () {
    [$user, $server] = actingOwnerWithRedis();

    $component = Livewire::actingAs($user)
        ->test(WorkspaceCaches::class, ['server' => $server])
        ->call('setWorkspaceTab', 'redis')
        ->set('replUnlocked', true)
        ->call('startMonitor', 10);

    // Wrong run id — chunk should be ignored.
    $component->call('onMonitorChunk', 'some-other-run', "junk line\n");

    expect($component->get('monitorPayload')['lines'])->toBe([]);
});
test('on completed clears run id', function () {
    [$user, $server] = actingOwnerWithRedis();

    $component = Livewire::actingAs($user)
        ->test(WorkspaceCaches::class, ['server' => $server])
        ->call('setWorkspaceTab', 'redis')
        ->set('replUnlocked', true)
        ->call('startMonitor', 10);

    $runId = $component->get('monitorRunId');

    $component->call('onMonitorCompleted', $runId, true, 42, null)
        ->assertSet('monitorRunId', '')
        ->assertSet('monitorPayload.status', 'completed');
});
test('monitor rejects memcached', function () {
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
        'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
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

    Livewire::actingAs($user)
        ->test(WorkspaceCaches::class, ['server' => $server])
        ->call('setWorkspaceTab', 'memcached')
        ->set('replUnlocked', true)
        ->call('startMonitor', 10)
        ->assertSet('monitorRunId', '');

    Queue::assertNotPushed(TailCacheServiceMonitorJob::class);
});
