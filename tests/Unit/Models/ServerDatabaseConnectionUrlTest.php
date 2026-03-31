<?php

namespace Tests\Unit\Models;

use App\Models\Server;
use App\Models\ServerDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerDatabaseConnectionUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_connection_url_encodes_special_characters(): void
    {
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

        $this->assertStringStartsWith('mysql://', $url);
        $this->assertStringContainsString(rawurlencode('user@x'), $url);
        $this->assertStringContainsString(rawurlencode('p@ss/w:ord'), $url);
        $this->assertStringContainsString('127.0.0.1:3306', $url);
        $this->assertStringEndsWith('/app_db', $url);
    }

    public function test_postgres_connection_url_uses_port_5432(): void
    {
        $server = Server::factory()->create();

        $db = new ServerDatabase([
            'server_id' => $server->id,
            'name' => 'app_db',
            'engine' => 'postgres',
            'username' => 'u',
            'password' => 'p',
            'host' => '10.0.0.1',
        ]);

        $this->assertStringStartsWith('postgresql://', $db->connectionUrl());
        $this->assertStringContainsString('10.0.0.1:5432', $db->connectionUrl());
    }
}
