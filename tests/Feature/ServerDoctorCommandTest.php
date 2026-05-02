<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ServerDoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_reports_clean_state_for_polyglot_server(): void
    {
        $server = Server::factory()->create([
            'name' => 'edge-1',
            'meta' => [
                'php_version' => '8.4',
                'runtime_defaults' => ['node' => '22', 'python' => '3.12'],
            ],
        ]);
        ServerDatabaseEngine::create([
            'server_id' => $server->id,
            'engine' => 'postgres',
            'version' => '17',
            'is_default' => true,
        ]);

        $exit = Artisan::call('dply:server:doctor', ['server' => 'edge-1']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('node', $output);
        $this->assertStringContainsString('22', $output);
        $this->assertStringContainsString('python', $output);
        $this->assertStringContainsString('3.12', $output);
        $this->assertStringContainsString('postgres', $output);
        $this->assertStringContainsString('No drift detected', $output);
    }

    public function test_command_flags_sites_with_unregistered_engine(): void
    {
        $server = Server::factory()->create(['name' => 'edge-1']);
        ServerDatabaseEngine::create([
            'server_id' => $server->id,
            'engine' => 'postgres',
            'is_default' => true,
        ]);
        Site::factory()->create([
            'server_id' => $server->id,
            'name' => 'orphan',
            'database_engine' => 'mariadb',
        ]);

        $exit = Artisan::call('dply:server:doctor', ['server' => 'edge-1']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('orphan', $output);
        $this->assertStringContainsString('mariadb', $output);
        $this->assertStringContainsString('NOT registered', $output);
    }

    public function test_command_flags_sites_with_non_pinned_runtime(): void
    {
        $server = Server::factory()->create([
            'name' => 'edge-1',
            'meta' => ['runtime_defaults' => ['node' => '22']],
        ]);
        Site::factory()->create([
            'server_id' => $server->id,
            'name' => 'pyapp',
            'runtime' => 'python',
            'runtime_version' => '3.12',
        ]);

        $exit = Artisan::call('dply:server:doctor', ['server' => 'edge-1']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('pyapp', $output);
        $this->assertStringContainsString('python', $output);
        $this->assertStringContainsString('mise installs on demand', $output);
    }

    public function test_command_does_not_flag_php_or_static_sites(): void
    {
        $server = Server::factory()->create([
            'name' => 'edge-1',
            'meta' => ['php_version' => '8.4'],
        ]);
        Site::factory()->create([
            'server_id' => $server->id,
            'name' => 'laravel-app',
            'runtime' => 'php',
            'runtime_version' => '8.4',
        ]);
        Site::factory()->create([
            'server_id' => $server->id,
            'name' => 'static-site',
            'runtime' => 'static',
        ]);

        $exit = Artisan::call('dply:server:doctor', ['server' => 'edge-1']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No drift detected', $output);
    }

    public function test_command_emits_json(): void
    {
        $server = Server::factory()->create([
            'name' => 'edge-1',
            'meta' => ['runtime_defaults' => ['node' => '22']],
        ]);
        ServerDatabaseEngine::create([
            'server_id' => $server->id,
            'engine' => 'postgres',
            'is_default' => true,
        ]);

        $exit = Artisan::call('dply:server:doctor', [
            'server' => 'edge-1',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame($server->id, $decoded['server_id']);
        $this->assertSame(['node' => '22'], $decoded['runtime_defaults']);
        $this->assertSame('postgres', $decoded['engines'][0]['engine']);
    }

    public function test_command_fails_when_server_not_found(): void
    {
        $exit = Artisan::call('dply:server:doctor', ['server' => 'no-such']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Server not found', $output);
    }
}
