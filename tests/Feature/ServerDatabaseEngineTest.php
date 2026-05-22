<?php

declare(strict_types=1);

namespace Tests\Feature\ServerDatabaseEngineTest;
use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use Illuminate\Database\UniqueConstraintViolationException;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('server can have multiple engines', function () {
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

    expect($engines)->toHaveCount(2);
    expect($engines->pluck('engine')->all())->toEqualCanonicalizing(['postgres', 'mysql84']);
});
test('default database engine returns the is default row', function () {
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

    expect($default)->not->toBeNull();
    expect($default->engine)->toBe('postgres');
});
test('default database engine is null when no engines installed', function () {
    $server = Server::factory()->create();

    expect($server->defaultDatabaseEngine())->toBeNull();
});
test('unique index blocks duplicate engine per server', function () {
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
});
test('unique index allows same engine on different servers', function () {
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

    expect(ServerDatabaseEngine::query()->where('engine', 'postgres')->count())->toBe(2);
});
test('cascade delete removes engines when server deleted', function () {
    $server = Server::factory()->create();
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'version' => '17',
        'is_default' => true,
    ]);

    $server->delete();

    expect(ServerDatabaseEngine::query()->where('server_id', $server->id)->count())->toBe(0);
});
