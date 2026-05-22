<?php

declare(strict_types=1);

namespace Tests\Feature\SeedProvisionedEnginesTest;
use App\Actions\Servers\SeedProvisionedEnginesForServer;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerDatabaseEngine;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('seeds cache and database rows from meta', function () {
    $server = makeServer([
        'meta' => [
            'cache_service' => 'redis',
            'database' => 'postgres18',
        ],
    ]);

    $result = app(SeedProvisionedEnginesForServer::class)->execute($server);

    expect($result['cache_created'])->toBeTrue();
    expect($result['database_created'])->toBeTrue();

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
});
test('skips when meta says none', function () {
    $server = makeServer([
        'meta' => [
            'cache_service' => 'none',
            'database' => 'none',
        ],
    ]);

    $result = app(SeedProvisionedEnginesForServer::class)->execute($server);

    expect($result['cache_created'])->toBeFalse();
    expect($result['database_created'])->toBeFalse();
    $this->assertDatabaseMissing('server_cache_services', ['server_id' => $server->id]);
    $this->assertDatabaseMissing('server_database_engines', ['server_id' => $server->id]);
});
test('skips when meta keys are absent', function () {
    $server = makeServer(['meta' => []]);

    $result = app(SeedProvisionedEnginesForServer::class)->execute($server);

    expect($result['cache_created'])->toBeFalse();
    expect($result['database_created'])->toBeFalse();
    $this->assertDatabaseMissing('server_cache_services', ['server_id' => $server->id]);
    $this->assertDatabaseMissing('server_database_engines', ['server_id' => $server->id]);
});
test('rejects unknown cache engine', function () {
    $server = makeServer([
        'meta' => ['cache_service' => 'memcached-but-typo'],
    ]);

    $result = app(SeedProvisionedEnginesForServer::class)->execute($server);

    expect($result['cache_created'])->toBeFalse();
    $this->assertDatabaseMissing('server_cache_services', ['server_id' => $server->id]);
});
test('double run is idempotent', function () {
    $server = makeServer([
        'meta' => [
            'cache_service' => 'valkey',
            'database' => 'mysql84',
        ],
    ]);

    $first = app(SeedProvisionedEnginesForServer::class)->execute($server);
    $second = app(SeedProvisionedEnginesForServer::class)->execute($server);

    expect($first['cache_created'])->toBeTrue();
    expect($first['database_created'])->toBeTrue();
    expect($second['cache_created'])->toBeFalse();
    expect($second['database_created'])->toBeFalse();

    expect(ServerCacheService::query()->where('server_id', $server->id)->count())->toBe(1);
    expect(ServerDatabaseEngine::query()->where('server_id', $server->id)->count())->toBe(1);
});
test('preserves existing row inserted by ui', function () {
    $server = makeServer([
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

    expect($result['cache_created'])->toBeFalse();
    $this->assertDatabaseHas('server_cache_services', [
        'server_id' => $server->id,
        'engine' => 'redis',
        'status' => ServerCacheService::STATUS_INSTALLING,
    ]);
});
test('uses correct default port per database engine', function () {
    $sqliteServer = makeServer(['meta' => ['database' => 'sqlite3']]);
    $mariadbServer = makeServer(['meta' => ['database' => 'mariadb1011']]);

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
});
test('backfill command dry run writes nothing', function () {
    $server = makeServer([
        'meta' => ['cache_service' => 'redis', 'database' => 'postgres18'],
    ]);

    $exit = Artisan::call('dply:servers:backfill-engine-rows', ['--dry-run' => true]);

    expect($exit)->toBe(0);
    $this->assertDatabaseMissing('server_cache_services', ['server_id' => $server->id]);
    $this->assertDatabaseMissing('server_database_engines', ['server_id' => $server->id]);
});
test('backfill command seeds matching server only', function () {
    $target = makeServer([
        'meta' => ['cache_service' => 'redis', 'database' => 'postgres18'],
    ]);
    $other = makeServer([
        'meta' => ['cache_service' => 'memcached', 'database' => 'mysql84'],
    ]);

    $exit = Artisan::call('dply:servers:backfill-engine-rows', ['--server' => $target->id]);

    expect($exit)->toBe(0);
    $this->assertDatabaseHas('server_cache_services', ['server_id' => $target->id, 'engine' => 'redis']);
    $this->assertDatabaseHas('server_database_engines', ['server_id' => $target->id, 'engine' => 'postgres18']);
    $this->assertDatabaseMissing('server_cache_services', ['server_id' => $other->id]);
    $this->assertDatabaseMissing('server_database_engines', ['server_id' => $other->id]);
});
test('backfill command seeds all ready servers', function () {
    $first = makeServer(['meta' => ['cache_service' => 'redis']]);
    $second = makeServer(['meta' => ['database' => 'postgres18']]);
    $pending = makeServer([
        'meta' => ['cache_service' => 'redis'],
        'setup_status' => Server::SETUP_STATUS_RUNNING,
    ]);

    $exit = Artisan::call('dply:servers:backfill-engine-rows');

    expect($exit)->toBe(0);
    $this->assertDatabaseHas('server_cache_services', ['server_id' => $first->id, 'engine' => 'redis']);
    $this->assertDatabaseHas('server_database_engines', ['server_id' => $second->id, 'engine' => 'postgres18']);

    // Only servers in setup_status=done get seeded — pending/running servers are mid-provision
    // and will be handled by the post-provision hook when they finish.
    $this->assertDatabaseMissing('server_cache_services', ['server_id' => $pending->id]);
});
/**
 * @param  array<string, mixed>  $overrides
 */
function makeServer(array $overrides = []): Server
{
    $user = User::factory()->create();

    return Server::factory()->ready()->create(array_merge([
        'user_id' => $user->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
    ], $overrides));
}
