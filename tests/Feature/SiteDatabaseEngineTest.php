<?php

declare(strict_types=1);

namespace Tests\Feature\SiteDatabaseEngineTest;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('site database engine returns explicit column when set', function () {
    $server = Server::factory()->create();
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'is_default' => true,
    ]);
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'mysql84',
        'is_default' => false,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'database_engine' => 'mysql84',
    ]);

    expect($site->databaseEngine())->toBe('mysql84');
});
test('site database engine falls back to server default when unset', function () {
    $server = Server::factory()->create();
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'version' => '17',
        'is_default' => true,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'database_engine' => null,
    ]);

    expect($site->databaseEngine())->toBe('postgres');
});
test('site database engine is null when server has no engines', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'database_engine' => null,
    ]);

    expect($site->databaseEngine())->toBeNull();
});
test('site database engine is fillable', function () {
    $server = Server::factory()->create();
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'database_engine' => 'postgres',
    ]);

    expect($site->refresh()->database_engine)->toBe('postgres');
});
test('explicit column wins even when not in server engines', function () {
    // Defensive: if a server drops an engine that a site was using,
    // the site's column still resolves (we don't FK-enforce). This
    // lets the dashboard surface the orphan + offer a "switch engine"
    // affordance rather than 500-erroring.
    $server = Server::factory()->create();
    ServerDatabaseEngine::create([
        'server_id' => $server->id,
        'engine' => 'postgres',
        'is_default' => true,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'database_engine' => 'mysql84',
    ]);

    expect($site->databaseEngine())->toBe('mysql84');
});
