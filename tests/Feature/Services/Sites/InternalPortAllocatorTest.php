<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Sites\InternalPortAllocatorTest;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\InternalPortAllocator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('allocates range start when no sites exist on server', function () {
    $server = Server::factory()->create();

    $port = (new InternalPortAllocator)->allocate($server->id);

    expect($port)->toBe(InternalPortAllocator::RANGE_START);
});
test('skips taken ports and returns lowest unused', function () {
    $server = Server::factory()->create();
    Site::factory()->create([
        'server_id' => $server->id,
        'internal_port' => InternalPortAllocator::RANGE_START,
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'internal_port' => InternalPortAllocator::RANGE_START + 1,
    ]);

    // Leave RANGE_START + 2 unused.
    Site::factory()->create([
        'server_id' => $server->id,
        'internal_port' => InternalPortAllocator::RANGE_START + 3,
    ]);

    $port = (new InternalPortAllocator)->allocate($server->id);

    expect($port)->toBe(InternalPortAllocator::RANGE_START + 2);
});
test('allocations are scoped per server', function () {
    $a = Server::factory()->create();
    $b = Server::factory()->create();
    Site::factory()->create([
        'server_id' => $a->id,
        'internal_port' => InternalPortAllocator::RANGE_START,
    ]);

    $portA = (new InternalPortAllocator)->allocate($a->id);
    $portB = (new InternalPortAllocator)->allocate($b->id);

    // Server A's first port is taken; allocator returns 30001.
    expect($portA)->toBe(InternalPortAllocator::RANGE_START + 1);

    // Server B is independent; the same port is free there.
    expect($portB)->toBe(InternalPortAllocator::RANGE_START);
});
test('ignores sites with null internal port', function () {
    $server = Server::factory()->create();

    // PHP/static sites carry a NULL internal_port — must not displace
    // the allocator off the start of the range.
    Site::factory()->count(5)->create([
        'server_id' => $server->id,
        'internal_port' => null,
    ]);

    $port = (new InternalPortAllocator)->allocate($server->id);

    expect($port)->toBe(InternalPortAllocator::RANGE_START);
});
test('unique index rejects duplicate port on same server', function () {
    $server = Server::factory()->create();
    Site::factory()->create([
        'server_id' => $server->id,
        'internal_port' => 30001,
    ]);

    $this->expectException(UniqueConstraintViolationException::class);

    Site::factory()->create([
        'server_id' => $server->id,
        'internal_port' => 30001,
    ]);
});
test('unique index allows same port on different servers', function () {
    $a = Server::factory()->create();
    $b = Server::factory()->create();
    Site::factory()->create(['server_id' => $a->id, 'internal_port' => 30005]);
    Site::factory()->create(['server_id' => $b->id, 'internal_port' => 30005]);

    expect(Site::query()->where('internal_port', 30005)->count())->toBe(2);
});
