<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteDatabaseEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_database_engine_returns_explicit_column_when_set(): void
    {
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

        $this->assertSame('mysql84', $site->databaseEngine());
    }

    public function test_site_database_engine_falls_back_to_server_default_when_unset(): void
    {
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

        $this->assertSame('postgres', $site->databaseEngine());
    }

    public function test_site_database_engine_is_null_when_server_has_no_engines(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'database_engine' => null,
        ]);

        $this->assertNull($site->databaseEngine());
    }

    public function test_site_database_engine_is_fillable(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'database_engine' => 'postgres',
        ]);

        $this->assertSame('postgres', $site->refresh()->database_engine);
    }

    public function test_explicit_column_wins_even_when_not_in_server_engines(): void
    {
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

        $this->assertSame('mysql84', $site->databaseEngine());
    }
}
