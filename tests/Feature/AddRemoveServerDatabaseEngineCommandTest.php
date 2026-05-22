<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AddRemoveServerDatabaseEngineCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_engine_registers_first_engine_as_default(): void
    {
        $server = $this->makeServer();

        $exit = Artisan::call('dply:server:add-engine', [
            'server' => $server->id,
            'engine' => 'postgres',
            '--engine-version' => '17',
        ]);

        $this->assertSame(0, $exit);
        $this->assertDatabaseHas('server_database_engines', [
            'server_id' => $server->id,
            'engine' => 'postgres',
            'version' => '17',
            'is_default' => true,
        ]);
    }

    public function test_add_engine_with_default_flag_overrides_existing_default(): void
    {
        $server = $this->makeServer();
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
    }

    public function test_add_engine_unknown_server_returns_failure(): void
    {
        $exit = Artisan::call('dply:server:add-engine', [
            'server' => 'no-such-server',
            'engine' => 'postgres',
        ]);

        $this->assertSame(1, $exit);
    }

    public function test_remove_engine_unregisters_from_server(): void
    {
        $server = $this->makeServer();
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

        $this->assertSame(0, $exit);
        $this->assertDatabaseMissing('server_database_engines', [
            'server_id' => $server->id,
            'engine' => 'postgres',
        ]);
    }

    public function test_remove_engine_refuses_when_sites_pin_it(): void
    {
        $server = $this->makeServer();
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

        $this->assertSame(1, $exit);
        $this->assertDatabaseHas('server_database_engines', [
            'server_id' => $server->id,
            'engine' => 'postgres',
        ]);
    }

    public function test_remove_engine_unknown_server_returns_failure(): void
    {
        $exit = Artisan::call('dply:server:remove-engine', [
            'server' => 'no-such-server',
            'engine' => 'postgres',
        ]);

        $this->assertSame(1, $exit);
    }

    public function test_resolve_server_by_name_or_ip(): void
    {
        $server = $this->makeServer(['name' => 'edge-1', 'ip_address' => '203.0.113.99']);

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
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeServer(array $overrides = []): Server
    {
        $user = User::factory()->create();

        return Server::factory()->ready()->create(array_merge([
            'user_id' => $user->id,
        ], $overrides));
    }
}
