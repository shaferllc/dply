<?php

declare(strict_types=1);

namespace Tests\Feature\AddRemoveServerDatabaseEngineCommandTest;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('add engine registers first engine as default', function () {
    $server = makeServer();

    $exit = Artisan::call('dply:server:add-engine', [
        'server' => $server->id,
        'engine' => 'postgres',
        '--engine-version' => '17',
    ]);

    expect($exit)->toBe(0);
    $this->assertDatabaseHas('server_database_engines', [
        'server_id' => $server->id,
        'engine' => 'postgres',
        'version' => '17',
        'is_default' => true,
    ]);
});
test('add engine with default flag overrides existing default', function () {
    $server = makeServer();
    ServerDatabaseEngine::query()->create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'version' => '17',
        'is_default' => true,
    ]);

    Artisan::call('dply:server:add-engine', [
        'server' => $server->id,
        'engine' => 'mysql84',
        '--engine-version' => '8.4',
        '--default' => true,
    ]);

    $this->assertDatabaseHas('server_database_engines', [
        'server_id' => $server->id,
        'engine' => 'mysql84',
        'is_default' => true,
    ]);
    $this->assertDatabaseHas('server_database_engines', [
        'server_id' => $server->id,
        'engine' => 'postgres',
        'is_default' => false,
    ]);
});
test('add engine unknown server returns failure', function () {
    $exit = Artisan::call('dply:server:add-engine', [
        'server' => 'no-such-server',
        'engine' => 'postgres',
    ]);

    expect($exit)->toBe(1);
});
test('remove engine unregisters from server', function () {
    $server = makeServer();
    ServerDatabaseEngine::query()->create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'version' => '17',
        'is_default' => true,
    ]);

    $exit = Artisan::call('dply:server:remove-engine', [
        'server' => $server->id,
        'engine' => 'postgres',
    ]);

    expect($exit)->toBe(0);
    $this->assertDatabaseMissing('server_database_engines', [
        'server_id' => $server->id,
        'engine' => 'postgres',
    ]);
});
test('remove engine refuses when sites pin it', function () {
    $server = makeServer();
    $user = User::query()->where('id', $server->user_id)->first();
    ServerDatabaseEngine::query()->create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'version' => '17',
        'is_default' => true,
    ]);
    Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'database_engine' => 'postgres',
    ]);

    $exit = Artisan::call('dply:server:remove-engine', [
        'server' => $server->id,
        'engine' => 'postgres',
    ]);

    expect($exit)->toBe(1);
    $this->assertDatabaseHas('server_database_engines', [
        'server_id' => $server->id,
        'engine' => 'postgres',
    ]);
});
test('remove engine unknown server returns failure', function () {
    $exit = Artisan::call('dply:server:remove-engine', [
        'server' => 'no-such-server',
        'engine' => 'postgres',
    ]);

    expect($exit)->toBe(1);
});
test('resolve server by name or ip', function () {
    $server = makeServer(['name' => 'edge-1', 'ip_address' => '203.0.113.99']);

    Artisan::call('dply:server:add-engine', [
        'server' => 'edge-1',
        'engine' => 'mariadb',
    ]);
    $this->assertDatabaseHas('server_database_engines', [
        'server_id' => $server->id,
        'engine' => 'mariadb',
    ]);

    Artisan::call('dply:server:add-engine', [
        'server' => '203.0.113.99',
        'engine' => 'postgres',
    ]);
    $this->assertDatabaseHas('server_database_engines', [
        'server_id' => $server->id,
        'engine' => 'postgres',
    ]);
});
/**
 * @param  array<string, mixed>  $overrides
 */
function makeServer(array $overrides = []): Server
{
    $user = User::factory()->create();

    return Server::factory()->ready()->create(array_merge([
        'user_id' => $user->id,
    ], $overrides));
}
