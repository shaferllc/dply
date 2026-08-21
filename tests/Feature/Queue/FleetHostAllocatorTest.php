<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\FleetHostAllocatorTest;

use App\Models\Server;
use App\Modules\Queue\Models\ManagedQueueWorker;
use App\Modules\Queue\Services\Runtimes\FleetHostAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;

uses(RefreshDatabase::class);

function host(int $capacityMib): Server
{
    return Server::factory()->create([
        'meta' => ['queue_fleet_host' => ['enabled' => true, 'capacity_mib' => $capacityMib]],
    ]);
}

function workerOn(Server $server, int $memoryMib, string $state = ManagedQueueWorker::STATE_RUNNING): ManagedQueueWorker
{
    return ManagedQueueWorker::query()->create([
        'fleet_id' => (string) Str::ulid(),
        'runtime' => 'docker',
        'runtime_ref' => $server->id.':c',
        'host_server_id' => $server->id,
        'state' => $state,
        'memory_mib' => $memoryMib,
        'started_at' => now(),
    ]);
}

test('a server is never a fleet host by accident', function () {
    Server::factory()->create(['meta' => ['host_kind' => 'vm']]);

    expect(fn () => app(FleetHostAllocator::class)->allocate(256))
        ->toThrow(RuntimeException::class, 'No queue fleet hosts');
});

test('placement spreads across hosts rather than packing one', function () {
    $busy = host(4096);
    $idle = host(4096);
    workerOn($busy, 2048);

    expect(app(FleetHostAllocator::class)->allocate(512)->id)->toBe($idle->id);
});

test('committed memory counts against a host, including workers still starting', function () {
    $small = host(1024);
    workerOn($small, 768, ManagedQueueWorker::STATE_STARTING);

    // 256 fits in the remaining 256; 512 does not.
    expect(app(FleetHostAllocator::class)->allocate(256)->id)->toBe($small->id)
        ->and(fn () => app(FleetHostAllocator::class)->allocate(512))
        ->toThrow(RuntimeException::class, '512 MiB free');
});

/** A stopped worker has handed its memory back. */
test('stopped workers stop reserving capacity', function () {
    $server = host(1024);
    workerOn($server, 1024, ManagedQueueWorker::STATE_STOPPED);

    expect(app(FleetHostAllocator::class)->allocate(1024)->id)->toBe($server->id);
});
