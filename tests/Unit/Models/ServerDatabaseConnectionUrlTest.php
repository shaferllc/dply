<?php


namespace Tests\Unit\Models\ServerDatabaseConnectionUrlTest;
use App\Models\Server;
use App\Models\ServerDatabase;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('mysql connection url encodes special characters', function () {
    $server = Server::factory()->create();

    $db = new ServerDatabase([
        'server_id' => $server->id,
        'name' => 'app_db',
        'engine' => 'mysql',
        'username' => 'user@x',
        'password' => 'p@ss/w:ord',
        'host' => '127.0.0.1',
    ]);

    $url = $db->connectionUrl();

    expect($url)->toStartWith('mysql://');
    $this->assertStringContainsString(rawurlencode('user@x'), $url);
    $this->assertStringContainsString(rawurlencode('p@ss/w:ord'), $url);
    $this->assertStringContainsString('127.0.0.1:3306', $url);
    expect($url)->toEndWith('/app_db');
});

test('postgres connection url uses port 5432', function () {
    $server = Server::factory()->create();

    $db = new ServerDatabase([
        'server_id' => $server->id,
        'name' => 'app_db',
        'engine' => 'postgres',
        'username' => 'u',
        'password' => 'p',
        'host' => '10.0.0.1',
    ]);

    expect($db->connectionUrl())->toStartWith('postgresql://');
    $this->assertStringContainsString('10.0.0.1:5432', $db->connectionUrl());
});

test('sqlite connection url uses file path from host column', function () {
    $server = Server::factory()->create();

    $db = new ServerDatabase([
        'server_id' => $server->id,
        'name' => 'app_db',
        'engine' => 'sqlite',
        'username' => '',
        'password' => '',
        'host' => '/var/lib/dply/sqlite/app_db.db',
    ]);

    expect($db->connectionUrl())->toBe('sqlite:/var/lib/dply/sqlite/app_db.db');
    expect($db->defaultPort())->toBe(0);
});

test('sqlite connection url falls back to default root when host missing', function () {
    $server = Server::factory()->create();

    $db = new ServerDatabase([
        'server_id' => $server->id,
        'name' => 'fallback_db',
        'engine' => 'sqlite',
        'username' => '',
        'password' => '',
        'host' => '',
    ]);

    expect($db->connectionUrl())->toBe('sqlite:/var/lib/dply/sqlite/fallback_db.db');
});