<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use Tests\TestCase;

class WorkspaceCachesMonitorTest extends TestCase
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
     * @return array{User, Server, ServerCacheService}
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

        $row = ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);

        return [$user, $server, $row];
    }

    public function test_start_monitor_requires_unlock(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->call('startMonitor', 10)
            ->assertSet('monitorRunId', '');

        Queue::assertNotPushed(TailCacheServiceMonitorJob::class);
    }

    public function test_start_monitor_dispatches_job_and_sets_run_id(): void
    {
        [$user, $server, $row] = $this->actingOwnerWithRedis();

        $component = Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->set('replUnlocked', true)
            ->call('startMonitor', 10)
            ->assertHasNoErrors();

        $this->assertNotEmpty($component->get('monitorRunId'), 'Run id must be set after start.');
        $this->assertSame(10, $component->get('monitorDurationSeconds'));
        $this->assertSame('queued', $component->get('monitorPayload.status'));

        Queue::assertPushed(
            TailCacheServiceMonitorJob::class,
            fn ($job) => $job->serverId === $server->id
                && $job->cacheServiceId === $row->id
                && $job->durationSeconds === 10,
        );
    }

    public function test_start_monitor_caps_duration_at_thirty_seconds(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->set('replUnlocked', true)
            ->call('startMonitor', 60)
            ->assertSet('monitorDurationSeconds', 30);
    }

    public function test_start_monitor_floors_duration_at_minimum(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->set('replUnlocked', true)
            ->call('startMonitor', 1)
            ->assertSet('monitorDurationSeconds', TailCacheServiceMonitorJob::MIN_DURATION);
    }

    public function test_start_monitor_rejects_when_already_running(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        $component = Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->set('replUnlocked', true)
            ->call('startMonitor', 10);

        $firstRunId = $component->get('monitorRunId');
        $this->assertNotEmpty($firstRunId);

        // Second call while still running should be rejected — same run id stays.
        $component->call('startMonitor', 10);
        $this->assertSame($firstRunId, $component->get('monitorRunId'));

        Queue::assertPushed(TailCacheServiceMonitorJob::class, 1);
    }

    public function test_poll_picks_up_cache_buffer(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

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
                $this->assertSame(
                    ['1700000000.123 [0 127.0.0.1:55012] "GET" "foo"'],
                    $c->get('monitorPayload.lines'),
                );
            });

        // Run id stays set while status is running.
        $this->assertSame($runId, $component->get('monitorRunId'));
    }

    public function test_poll_clears_run_id_on_completion(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

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
        $this->assertCount(2, $component->get('monitorPayload.lines'));
    }

    public function test_clear_monitor_output_resets_state(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->set('replUnlocked', true)
            ->call('startMonitor', 10)
            ->call('clearMonitorOutput')
            ->assertSet('monitorRunId', '')
            ->assertSet('monitorPayload', null);
    }

    public function test_on_chunk_appends_lines_to_payload(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        $component = Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->set('replUnlocked', true)
            ->call('startMonitor', 10);

        $runId = $component->get('monitorRunId');

        $component->call('onMonitorChunk', $runId, "1700000000.111 [0 client] \"GET\" \"a\"\n1700000000.112 [0 client] \"GET\" \"b\"\n");

        $payload = $component->get('monitorPayload');
        $this->assertSame('running', $payload['status']);
        $this->assertSame(
            ['1700000000.111 [0 client] "GET" "a"', '1700000000.112 [0 client] "GET" "b"'],
            $payload['lines'],
        );
    }

    public function test_on_chunk_drops_events_for_other_run_ids(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        $component = Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->set('replUnlocked', true)
            ->call('startMonitor', 10);

        // Wrong run id — chunk should be ignored.
        $component->call('onMonitorChunk', 'some-other-run', "junk line\n");

        $this->assertSame([], $component->get('monitorPayload')['lines']);
    }

    public function test_on_completed_clears_run_id(): void
    {
        [$user, $server] = $this->actingOwnerWithRedis();

        $component = Livewire::actingAs($user)
            ->test(WorkspaceCaches::class, ['server' => $server])
            ->call('setWorkspaceTab', 'redis')
            ->set('replUnlocked', true)
            ->call('startMonitor', 10);

        $runId = $component->get('monitorRunId');

        $component->call('onMonitorCompleted', $runId, true, 42, null)
            ->assertSet('monitorRunId', '')
            ->assertSet('monitorPayload.status', 'completed');
    }

    public function test_monitor_rejects_memcached(): void
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
    }
}
