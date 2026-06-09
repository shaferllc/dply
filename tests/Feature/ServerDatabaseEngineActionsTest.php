<?php

declare(strict_types=1);

namespace Tests\Feature\ServerDatabaseEngineActionsTest;

use App\Actions\Servers\AttachDatabaseEngineToServer;
use App\Actions\Servers\DetachDatabaseEngineFromServer;
use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('attach first engine marks it default automatically', function () {
    $server = Server::factory()->create();

    $row = (new AttachDatabaseEngineToServer)->execute($server, 'postgres', '17');

    expect($row->engine)->toBe('postgres');
    expect($row->version)->toBe('17');
    expect($row->is_default)->toBeTrue();
});
test('attach second engine does not steal default unless explicit', function () {
    $server = Server::factory()->create();
    (new AttachDatabaseEngineToServer)->execute($server, 'postgres', '17');

    $mysql = (new AttachDatabaseEngineToServer)->execute($server, 'mysql84', '8.4');

    expect($mysql->is_default)->toBeFalse();
    $postgres = ServerDatabaseEngine::query()->where('server_id', $server->id)->where('engine', 'postgres')->firstOrFail();
    expect($postgres->is_default)->toBeTrue();
});
test('attach with default flag steals default from other engines', function () {
    $server = Server::factory()->create();
    (new AttachDatabaseEngineToServer)->execute($server, 'postgres', '17');

    $mysql = (new AttachDatabaseEngineToServer)->execute($server, 'mysql84', '8.4', isDefault: true);

    expect($mysql->is_default)->toBeTrue();
    $postgres = ServerDatabaseEngine::query()->where('server_id', $server->id)->where('engine', 'postgres')->firstOrFail();
    expect($postgres->is_default)->toBeFalse();
});
test('attach is idempotent and updates existing row', function () {
    $server = Server::factory()->create();
    (new AttachDatabaseEngineToServer)->execute($server, 'postgres', '16');

    $updated = (new AttachDatabaseEngineToServer)->execute($server, 'postgres', '17');

    expect($updated->version)->toBe('17');
    expect(ServerDatabaseEngine::query()->where('server_id', $server->id)->count())->toBe(1);
});
test('attach throws for blank engine', function () {
    $server = Server::factory()->create();

    $this->expectException(\InvalidArgumentException::class);

    (new AttachDatabaseEngineToServer)->execute($server, '   ');
});
test('detach unregisters engine when no sites use it', function () {
    $server = Server::factory()->create();
    (new AttachDatabaseEngineToServer)->execute($server, 'postgres', '17');
    (new AttachDatabaseEngineToServer)->execute($server, 'mysql84', '8.4');

    $result = (new DetachDatabaseEngineFromServer)->execute($server, 'mysql84');

    expect($result['ok'])->toBeTrue();
    expect(ServerDatabaseEngine::query()->where('server_id', $server->id)->where('engine', 'mysql84')->count())->toBe(0);
});
test('detach promotes alphabetical first engine when default removed', function () {
    $server = Server::factory()->create();
    (new AttachDatabaseEngineToServer)->execute($server, 'postgres', '17');
    (new AttachDatabaseEngineToServer)->execute($server, 'mysql84', '8.4');
    (new AttachDatabaseEngineToServer)->execute($server, 'mariadb', '11.4');

    // postgres was the first registered → default. Detach it.
    $result = (new DetachDatabaseEngineFromServer)->execute($server, 'postgres');
    expect($result['ok'])->toBeTrue();

    // mariadb is alphabetically first among the remaining → new default.
    $newDefault = $server->refresh()->defaultDatabaseEngine();
    expect($newDefault)->not->toBeNull();
    expect($newDefault->engine)->toBe('mariadb');
});
test('detach refuses when a site still targets the engine', function () {
    $server = Server::factory()->create();
    (new AttachDatabaseEngineToServer)->execute($server, 'postgres', '17');
    (new AttachDatabaseEngineToServer)->execute($server, 'mysql84', '8.4');

    Site::factory()->create([
        'server_id' => $server->id,
        'name' => 'reports-app',
        'database_engine' => 'mysql84',
    ]);

    $result = (new DetachDatabaseEngineFromServer)->execute($server, 'mysql84');

    expect($result['ok'])->toBeFalse();
    expect($result['sites_using_engine'])->toContain('reports-app');
    expect(ServerDatabaseEngine::query()->where('server_id', $server->id)->where('engine', 'mysql84')->count())->toBe(1);
});
test('detach is a noop when engine not registered', function () {
    $server = Server::factory()->create();
    (new AttachDatabaseEngineToServer)->execute($server, 'postgres', '17');

    $result = (new DetachDatabaseEngineFromServer)->execute($server, 'mysql84');

    expect($result['ok'])->toBeTrue();
});
test('add engine command registers engine', function () {
    $server = Server::factory()->create(['name' => 'edge-1']);

    $exit = Artisan::call('dply:server:add-engine', [
        'server' => 'edge-1',
        'engine' => 'postgres',
        '--engine-version' => '17',
        '--default' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Registered postgres 17 on edge-1', $output);
    expect(ServerDatabaseEngine::query()->where('server_id', $server->id)->count())->toBe(1);
});
test('add engine command fails for unknown server', function () {
    $exit = Artisan::call('dply:server:add-engine', [
        'server' => 'no-such-server',
        'engine' => 'postgres',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Server not found', $output);
});
test('remove engine command blocks when sites pin engine', function () {
    $server = Server::factory()->create(['name' => 'edge-1']);
    (new AttachDatabaseEngineToServer)->execute($server, 'postgres', '17');
    (new AttachDatabaseEngineToServer)->execute($server, 'mysql84', '8.4');
    Site::factory()->create([
        'server_id' => $server->id,
        'name' => 'reports',
        'database_engine' => 'mysql84',
    ]);

    $exit = Artisan::call('dply:server:remove-engine', [
        'server' => 'edge-1',
        'engine' => 'mysql84',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('reports', $output);
});
