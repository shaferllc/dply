<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Servers\AttachDatabaseEngineToServer;
use App\Actions\Servers\DetachDatabaseEngineFromServer;
use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ServerDatabaseEngineActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_attach_first_engine_marks_it_default_automatically(): void
    {
        $server = Server::factory()->create();

        $row = (new AttachDatabaseEngineToServer)->execute($server, 'postgres', '17');

        $this->assertSame('postgres', $row->engine);
        $this->assertSame('17', $row->version);
        $this->assertTrue($row->is_default);
    }

    public function test_attach_second_engine_does_not_steal_default_unless_explicit(): void
    {
        $server = Server::factory()->create();
        (new AttachDatabaseEngineToServer)->execute($server, 'postgres', '17');

        $mysql = (new AttachDatabaseEngineToServer)->execute($server, 'mysql84', '8.4');

        $this->assertFalse($mysql->is_default);
        $postgres = ServerDatabaseEngine::query()->where('server_id', $server->id)->where('engine', 'postgres')->firstOrFail();
        $this->assertTrue($postgres->is_default);
    }

    public function test_attach_with_default_flag_steals_default_from_other_engines(): void
    {
        $server = Server::factory()->create();
        (new AttachDatabaseEngineToServer)->execute($server, 'postgres', '17');

        $mysql = (new AttachDatabaseEngineToServer)->execute($server, 'mysql84', '8.4', isDefault: true);

        $this->assertTrue($mysql->is_default);
        $postgres = ServerDatabaseEngine::query()->where('server_id', $server->id)->where('engine', 'postgres')->firstOrFail();
        $this->assertFalse($postgres->is_default);
    }

    public function test_attach_is_idempotent_and_updates_existing_row(): void
    {
        $server = Server::factory()->create();
        (new AttachDatabaseEngineToServer)->execute($server, 'postgres', '16');

        $updated = (new AttachDatabaseEngineToServer)->execute($server, 'postgres', '17');

        $this->assertSame('17', $updated->version);
        $this->assertSame(1, ServerDatabaseEngine::query()->where('server_id', $server->id)->count());
    }

    public function test_attach_throws_for_blank_engine(): void
    {
        $server = Server::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        (new AttachDatabaseEngineToServer)->execute($server, '   ');
    }

    public function test_detach_unregisters_engine_when_no_sites_use_it(): void
    {
        $server = Server::factory()->create();
        (new AttachDatabaseEngineToServer)->execute($server, 'postgres', '17');
        (new AttachDatabaseEngineToServer)->execute($server, 'mysql84', '8.4');

        $result = (new DetachDatabaseEngineFromServer)->execute($server, 'mysql84');

        $this->assertTrue($result['ok']);
        $this->assertSame(0, ServerDatabaseEngine::query()->where('server_id', $server->id)->where('engine', 'mysql84')->count());
    }

    public function test_detach_promotes_alphabetical_first_engine_when_default_removed(): void
    {
        $server = Server::factory()->create();
        (new AttachDatabaseEngineToServer)->execute($server, 'postgres', '17');
        (new AttachDatabaseEngineToServer)->execute($server, 'mysql84', '8.4');
        (new AttachDatabaseEngineToServer)->execute($server, 'mariadb', '11.4');

        // postgres was the first registered → default. Detach it.
        $result = (new DetachDatabaseEngineFromServer)->execute($server, 'postgres');
        $this->assertTrue($result['ok']);

        // mariadb is alphabetically first among the remaining → new default.
        $newDefault = $server->refresh()->defaultDatabaseEngine();
        $this->assertNotNull($newDefault);
        $this->assertSame('mariadb', $newDefault->engine);
    }

    public function test_detach_refuses_when_a_site_still_targets_the_engine(): void
    {
        $server = Server::factory()->create();
        (new AttachDatabaseEngineToServer)->execute($server, 'postgres', '17');
        (new AttachDatabaseEngineToServer)->execute($server, 'mysql84', '8.4');

        Site::factory()->create([
            'server_id' => $server->id,
            'name' => 'reports-app',
            'database_engine' => 'mysql84',
        ]);

        $result = (new DetachDatabaseEngineFromServer)->execute($server, 'mysql84');

        $this->assertFalse($result['ok']);
        $this->assertContains('reports-app', $result['sites_using_engine']);
        $this->assertSame(1, ServerDatabaseEngine::query()->where('server_id', $server->id)->where('engine', 'mysql84')->count());
    }

    public function test_detach_is_a_noop_when_engine_not_registered(): void
    {
        $server = Server::factory()->create();
        (new AttachDatabaseEngineToServer)->execute($server, 'postgres', '17');

        $result = (new DetachDatabaseEngineFromServer)->execute($server, 'mysql84');

        $this->assertTrue($result['ok']);
    }

    public function test_add_engine_command_registers_engine(): void
    {
        $server = Server::factory()->create(['name' => 'edge-1']);

        $exit = Artisan::call('dply:server:add-engine', [
            'server' => 'edge-1',
            'engine' => 'postgres',
            '--engine-version' => '17',
            '--default' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Registered postgres 17 on edge-1', $output);
        $this->assertSame(1, ServerDatabaseEngine::query()->where('server_id', $server->id)->count());
    }

    public function test_add_engine_command_fails_for_unknown_server(): void
    {
        $exit = Artisan::call('dply:server:add-engine', [
            'server' => 'no-such-server',
            'engine' => 'postgres',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Server not found', $output);
    }

    public function test_remove_engine_command_blocks_when_sites_pin_engine(): void
    {
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

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('reports', $output);
    }
}
