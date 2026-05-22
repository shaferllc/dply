<?php

declare(strict_types=1);

namespace Tests\Feature\WorkspaceCachesKeyspaceTest;

use App\Livewire\Servers\WorkspaceCaches;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\User;
use App\Support\Servers\CacheServiceStats;
use App\Support\Servers\ServerCacheServiceHostCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);

uses(WithFeatures::class);

beforeEach(function () {
    Queue::fake();

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
test('loading dashboard appends a sample', function () {
    [$user, $server] = actingOwnerWithRedis();

    $this->mock(CacheServiceStats::class, function ($mock): void {
        $mock->shouldReceive('snapshot')->byDefault()->andReturn([]);
        $mock->shouldReceive('forget')->byDefault()->andReturnNull();
        $mock->shouldReceive('rawInfo')->andReturn(
            "# Memory\nused_memory:1048576\nused_memory_human:1.00M\nconnected_clients:5\n"
            ."keyspace_hits:100\nkeyspace_misses:25\ntotal_commands_processed:200\n"
        );
    });

    Livewire::actingAs($user)
        ->test(WorkspaceCaches::class, ['server' => $server])
        ->call('setWorkspaceTab', 'redis')
        ->call('loadKeyspaceDashboard')
        ->assertHasNoErrors()
        ->assertSet('keyspaceLoaded', true)
        ->tap(function ($component): void {
            $samples = $component->get('keyspaceSamples');
            expect($samples)->toHaveCount(1);
            expect($samples[0]['used_memory'])->toBe(1048576);
            expect($samples[0]['used_memory_human'])->toBe('1.00M');
            expect($samples[0]['connected_clients'])->toBe(5);
            expect($samples[0]['hit_rate_window'])->toBeNull('first sample has no previous to delta against');
        });
});
test('second sample computes hit rate window', function () {
    [$user, $server] = actingOwnerWithRedis();

    $this->mock(CacheServiceStats::class, function ($mock): void {
        $mock->shouldReceive('snapshot')->byDefault()->andReturn([]);
        $mock->shouldReceive('forget')->byDefault()->andReturnNull();
        $mock->shouldReceive('rawInfo')
            ->andReturn(
                "used_memory:1048576\nused_memory_human:1.00M\nconnected_clients:5\n"
                ."keyspace_hits:100\nkeyspace_misses:25\ntotal_commands_processed:200\n",
                "used_memory:2097152\nused_memory_human:2.00M\nconnected_clients:6\n"
                ."keyspace_hits:130\nkeyspace_misses:35\ntotal_commands_processed:250\n",
            );
    });

    $component = Livewire::actingAs($user)
        ->test(WorkspaceCaches::class, ['server' => $server])
        ->call('setWorkspaceTab', 'redis')
        ->call('loadKeyspaceDashboard');

    // Force the second sample to claim a different timestamp by sleeping 1s.
    // (The sampler uses time() internally — we let it fall through.)
    sleep(1);

    $component->call('loadKeyspaceDashboard');

    $samples = $component->get('keyspaceSamples');
    expect($samples)->toHaveCount(2);
    expect($samples[1]['used_memory'])->toBe(2097152);

    // 30 hits, 10 misses → 30/40 = 0.75
    expect($samples[1]['hit_rate_window'])->toEqualWithDelta(0.75, 0.001);

    // 50 commands over ≥1 second → ≥1 op/sec but accept anything > 0
    expect($samples[1]['ops_per_second_window'])->toBeGreaterThan(0.0);
});
test('sample buffer caps at limit', function () {
    [$user, $server] = actingOwnerWithRedis();

    $this->mock(CacheServiceStats::class, function ($mock): void {
        $mock->shouldReceive('snapshot')->byDefault()->andReturn([]);
        $mock->shouldReceive('forget')->byDefault()->andReturnNull();
        $mock->shouldReceive('rawInfo')->andReturn("used_memory:1\nkeyspace_hits:1\nkeyspace_misses:0\ntotal_commands_processed:1\n");
    });

    $fakeSamples = [];
    for ($i = 0; $i < WorkspaceCaches::KEYSPACE_SAMPLE_LIMIT + 5; $i++) {
        $fakeSamples[] = [
            'ts' => 1000 + $i,
            'used_memory' => $i,
            'used_memory_human' => '1B',
            'connected_clients' => 0,
            'hits' => 0,
            'misses' => 0,
            'commands' => 0,
            'ops_per_second_window' => null,
            'hit_rate_window' => null,
        ];
    }

    $component = Livewire::actingAs($user)
        ->test(WorkspaceCaches::class, ['server' => $server])
        ->call('setWorkspaceTab', 'redis')
        ->set('keyspaceSamples', $fakeSamples)
        ->call('loadKeyspaceDashboard');

    $samples = $component->get('keyspaceSamples');
    expect($samples)->toHaveCount(WorkspaceCaches::KEYSPACE_SAMPLE_LIMIT);
});
test('hide dashboard wipes state', function () {
    [$user, $server] = actingOwnerWithRedis();

    $this->mock(CacheServiceStats::class, function ($mock): void {
        $mock->shouldReceive('snapshot')->byDefault()->andReturn([]);
        $mock->shouldReceive('forget')->byDefault()->andReturnNull();
        $mock->shouldReceive('rawInfo')->andReturn("used_memory:1\nkeyspace_hits:0\nkeyspace_misses:0\ntotal_commands_processed:0\n");
    });

    Livewire::actingAs($user)
        ->test(WorkspaceCaches::class, ['server' => $server])
        ->call('setWorkspaceTab', 'redis')
        ->call('loadKeyspaceDashboard')
        ->assertSet('keyspaceLoaded', true)
        ->call('hideKeyspaceDashboard')
        ->assertSet('keyspaceLoaded', false)
        ->assertSet('keyspaceSamples', [])
        ->assertSet('keyspaceError', null);
});
test('dashboard rejects memcached', function () {
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

    $this->mock(CacheServiceStats::class, function ($mock): void {
        $mock->shouldReceive('snapshot')->byDefault()->andReturn([]);
        $mock->shouldReceive('forget')->byDefault()->andReturnNull();
        $mock->shouldNotReceive('rawInfo');
    });

    Livewire::actingAs($user)
        ->test(WorkspaceCaches::class, ['server' => $server])
        ->call('setWorkspaceTab', 'memcached')
        ->call('loadKeyspaceDashboard')
        ->assertSet('keyspaceLoaded', false)
        ->tap(function ($component): void {
            expect($component->get('keyspaceError'))->not->toBeNull();
        });
});
