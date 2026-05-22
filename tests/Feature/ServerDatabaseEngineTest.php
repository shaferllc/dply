<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerDatabaseEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_can_have_multiple_engines(): void
    {
        $server = Server::factory()->create();

        ServerDatabaseEngine::create([
            'server_id' => $server->id,
            'engine' => 'postgres',
            'version' => '17',
            'is_default' => true,
        ]);
        ServerDatabaseEngine::create([
            'server_id' => $server->id,
            'engine' => 'mysql84',
            'version' => '8.4',
            'is_default' => false,
        ]);

        $engines = $server->refresh()->databaseEngines;

        $this->assertCount(2, $engines);
        $this->assertEqualsCanonicalizing(['postgres', 'mysql84'], $engines->pluck('engine')->all());
    }

    public function test_default_database_engine_returns_the_is_default_row(): void
    {
        $server = Server::factory()->create();
        ServerDatabaseEngine::create([
            'server_id' => $server->id,
            'engine' => 'postgres',
            'version' => '17',
            'is_default' => true,
        ]);
        ServerDatabaseEngine::create([
            'server_id' => $server->id,
            'engine' => 'mysql84',
            'version' => '8.4',
            'is_default' => false,
        ]);

        $default = $server->defaultDatabaseEngine();

        $this->assertNotNull($default);
        $this->assertSame('postgres', $default->engine);
    }

    public function test_default_database_engine_is_null_when_no_engines_installed(): void
    {
        $server = Server::factory()->create();

        $this->assertNull($server->defaultDatabaseEngine());
    }

    public function test_unique_index_blocks_duplicate_engine_per_server(): void
    {
        $server = Server::factory()->create();
        ServerDatabaseEngine::create([
            'server_id' => $server->id,
            'engine' => 'postgres',
            'version' => '17',
            'is_default' => true,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        ServerDatabaseEngine::create([
            'server_id' => $server->id,
            'engine' => 'postgres',
            'version' => '16',
            'is_default' => false,
        ]);
    }

    public function test_unique_index_allows_same_engine_on_different_servers(): void
    {
        $a = Server::factory()->create();
        $b = Server::factory()->create();

        ServerDatabaseEngine::create([
            'server_id' => $a->id,
            'engine' => 'postgres',
            'version' => '17',
            'is_default' => true,
        ]);
        ServerDatabaseEngine::create([
            'server_id' => $b->id,
            'engine' => 'postgres',
            'version' => '17',
            'is_default' => true,
        ]);

        $this->assertSame(2, ServerDatabaseEngine::query()->where('engine', 'postgres')->count());
    }

    public function test_cascade_delete_removes_engines_when_server_deleted(): void
    {
        $server = Server::factory()->create();
        ServerDatabaseEngine::create([
            'server_id' => $server->id,
            'engine' => 'postgres',
            'version' => '17',
            'is_default' => true,
        ]);

        $server->delete();

        $this->assertSame(0, ServerDatabaseEngine::query()->where('server_id', $server->id)->count());
    }
}
