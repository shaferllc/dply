<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Sites;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\InternalPortAllocator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalPortAllocatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_allocates_range_start_when_no_sites_exist_on_server(): void
    {
        $server = Server::factory()->create();

        $port = (new InternalPortAllocator)->allocate($server->id);

        $this->assertSame(InternalPortAllocator::RANGE_START, $port);
    }

    public function test_skips_taken_ports_and_returns_lowest_unused(): void
    {
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

        $this->assertSame(InternalPortAllocator::RANGE_START + 2, $port);
    }

    public function test_allocations_are_scoped_per_server(): void
    {
        $a = Server::factory()->create();
        $b = Server::factory()->create();
        Site::factory()->create([
            'server_id' => $a->id,
            'internal_port' => InternalPortAllocator::RANGE_START,
        ]);

        $portA = (new InternalPortAllocator)->allocate($a->id);
        $portB = (new InternalPortAllocator)->allocate($b->id);

        // Server A's first port is taken; allocator returns 30001.
        $this->assertSame(InternalPortAllocator::RANGE_START + 1, $portA);
        // Server B is independent; the same port is free there.
        $this->assertSame(InternalPortAllocator::RANGE_START, $portB);
    }

    public function test_ignores_sites_with_null_internal_port(): void
    {
        $server = Server::factory()->create();
        // PHP/static sites carry a NULL internal_port — must not displace
        // the allocator off the start of the range.
        Site::factory()->count(5)->create([
            'server_id' => $server->id,
            'internal_port' => null,
        ]);

        $port = (new InternalPortAllocator)->allocate($server->id);

        $this->assertSame(InternalPortAllocator::RANGE_START, $port);
    }

    public function test_unique_index_rejects_duplicate_port_on_same_server(): void
    {
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
    }

    public function test_unique_index_allows_same_port_on_different_servers(): void
    {
        $a = Server::factory()->create();
        $b = Server::factory()->create();
        Site::factory()->create(['server_id' => $a->id, 'internal_port' => 30005]);
        Site::factory()->create(['server_id' => $b->id, 'internal_port' => 30005]);

        $this->assertSame(2, Site::query()->where('internal_port', 30005)->count());
    }
}
