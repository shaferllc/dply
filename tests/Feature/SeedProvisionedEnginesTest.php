<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Servers\SeedProvisionedEnginesForServer;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerDatabaseEngine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SeedProvisionedEnginesTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_cache_and_database_rows_from_meta(): void
    {
        $server = $this->makeServer([
            'meta' => [
                'cache_service' => 'redis',
                'database' => 'postgres18',
            ],
        ]);

        $result = app(SeedProvisionedEnginesForServer::class)->execute($server);

        $this->assertTrue($result['cache_created']);
        $this->assertTrue($result['database_created']);

        $this->assertDatabaseHas('server_cache_services', [
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => 'default',
            'status' => ServerCacheService::STATUS_RUNNING,
            'port' => 6379,
        ]);
        $this->assertDatabaseHas('server_database_engines', [
            'server_id' => $server->id,
            'engine' => 'postgres18',
            'is_default' => true,
            'status' => ServerDatabaseEngine::STATUS_RUNNING,
            'port' => 5432,
        ]);
    }

    public function test_skips_when_meta_says_none(): void
    {
        $server = $this->makeServer([
            'meta' => [
                'cache_service' => 'none',
                'database' => 'none',
            ],
        ]);

        $result = app(SeedProvisionedEnginesForServer::class)->execute($server);

        $this->assertFalse($result['cache_created']);
        $this->assertFalse($result['database_created']);
        $this->assertDatabaseMissing('server_cache_services', ['server_id' => $server->id]);
        $this->assertDatabaseMissing('server_database_engines', ['server_id' => $server->id]);
    }

    public function test_skips_when_meta_keys_are_absent(): void
    {
        $server = $this->makeServer(['meta' => []]);

        $result = app(SeedProvisionedEnginesForServer::class)->execute($server);

        $this->assertFalse($result['cache_created']);
        $this->assertFalse($result['database_created']);
        $this->assertDatabaseMissing('server_cache_services', ['server_id' => $server->id]);
        $this->assertDatabaseMissing('server_database_engines', ['server_id' => $server->id]);
    }

    public function test_rejects_unknown_cache_engine(): void
    {
        $server = $this->makeServer([
            'meta' => ['cache_service' => 'memcached-but-typo'],
        ]);

        $result = app(SeedProvisionedEnginesForServer::class)->execute($server);

        $this->assertFalse($result['cache_created']);
        $this->assertDatabaseMissing('server_cache_services', ['server_id' => $server->id]);
    }

    public function test_double_run_is_idempotent(): void
    {
        $server = $this->makeServer([
            'meta' => [
                'cache_service' => 'valkey',
                'database' => 'mysql84',
            ],
        ]);

        $first = app(SeedProvisionedEnginesForServer::class)->execute($server);
        $second = app(SeedProvisionedEnginesForServer::class)->execute($server);

        $this->assertTrue($first['cache_created']);
        $this->assertTrue($first['database_created']);
        $this->assertFalse($second['cache_created']);
        $this->assertFalse($second['database_created']);

        $this->assertSame(1, ServerCacheService::query()->where('server_id', $server->id)->count());
        $this->assertSame(1, ServerDatabaseEngine::query()->where('server_id', $server->id)->count());
    }

    public function test_preserves_existing_row_inserted_by_ui(): void
    {
        $server = $this->makeServer([
            'meta' => ['cache_service' => 'redis'],
        ]);

        // Simulate the UI install path: row already exists with a different status (e.g. a manual
        // install in progress). The seeder must not overwrite it.
        ServerCacheService::query()->create([
            'server_id' => $server->id,
            'engine' => 'redis',
            'name' => 'default',
            'status' => ServerCacheService::STATUS_INSTALLING,
            'port' => 6379,
        ]);

        $result = app(SeedProvisionedEnginesForServer::class)->execute($server);

        $this->assertFalse($result['cache_created']);
        $this->assertDatabaseHas('server_cache_services', [
            'server_id' => $server->id,
            'engine' => 'redis',
            'status' => ServerCacheService::STATUS_INSTALLING,
        ]);
    }

    public function test_uses_correct_default_port_per_database_engine(): void
    {
        $sqliteServer = $this->makeServer(['meta' => ['database' => 'sqlite3']]);
        $mariadbServer = $this->makeServer(['meta' => ['database' => 'mariadb1011']]);

        app(SeedProvisionedEnginesForServer::class)->execute($sqliteServer);
        app(SeedProvisionedEnginesForServer::class)->execute($mariadbServer);

        $this->assertDatabaseHas('server_database_engines', [
            'server_id' => $sqliteServer->id,
            'engine' => 'sqlite3',
            'port' => 0,
        ]);
        $this->assertDatabaseHas('server_database_engines', [
            'server_id' => $mariadbServer->id,
            'engine' => 'mariadb1011',
            'port' => 3306,
        ]);
    }

    public function test_backfill_command_dry_run_writes_nothing(): void
    {
        $server = $this->makeServer([
            'meta' => ['cache_service' => 'redis', 'database' => 'postgres18'],
        ]);

        $exit = Artisan::call('dply:servers:backfill-engine-rows', ['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertDatabaseMissing('server_cache_services', ['server_id' => $server->id]);
        $this->assertDatabaseMissing('server_database_engines', ['server_id' => $server->id]);
    }

    public function test_backfill_command_seeds_matching_server_only(): void
    {
        $target = $this->makeServer([
            'meta' => ['cache_service' => 'redis', 'database' => 'postgres18'],
        ]);
        $other = $this->makeServer([
            'meta' => ['cache_service' => 'memcached', 'database' => 'mysql84'],
        ]);

        $exit = Artisan::call('dply:servers:backfill-engine-rows', ['--server' => $target->id]);

        $this->assertSame(0, $exit);
        $this->assertDatabaseHas('server_cache_services', ['server_id' => $target->id, 'engine' => 'redis']);
        $this->assertDatabaseHas('server_database_engines', ['server_id' => $target->id, 'engine' => 'postgres18']);
        $this->assertDatabaseMissing('server_cache_services', ['server_id' => $other->id]);
        $this->assertDatabaseMissing('server_database_engines', ['server_id' => $other->id]);
    }

    public function test_backfill_command_seeds_all_ready_servers(): void
    {
        $first = $this->makeServer(['meta' => ['cache_service' => 'redis']]);
        $second = $this->makeServer(['meta' => ['database' => 'postgres18']]);
        $pending = $this->makeServer([
            'meta' => ['cache_service' => 'redis'],
            'setup_status' => Server::SETUP_STATUS_RUNNING,
        ]);

        $exit = Artisan::call('dply:servers:backfill-engine-rows');

        $this->assertSame(0, $exit);
        $this->assertDatabaseHas('server_cache_services', ['server_id' => $first->id, 'engine' => 'redis']);
        $this->assertDatabaseHas('server_database_engines', ['server_id' => $second->id, 'engine' => 'postgres18']);
        // Only servers in setup_status=done get seeded — pending/running servers are mid-provision
        // and will be handled by the post-provision hook when they finish.
        $this->assertDatabaseMissing('server_cache_services', ['server_id' => $pending->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeServer(array $overrides = []): Server
    {
        $user = User::factory()->create();

        return Server::factory()->ready()->create(array_merge([
            'user_id' => $user->id,
            'setup_status' => Server::SETUP_STATUS_DONE,
        ], $overrides));
    }
}
