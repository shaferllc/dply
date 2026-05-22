<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ShowServerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_full_server_profile_in_json(): void
    {
        $server = Server::factory()->create([
            'name' => 'prod-1',
            'ip_address' => '203.0.113.10',
            'meta' => [
                'php_version' => '8.4',
                'runtime_defaults' => ['node' => '22.1.0', 'python' => '3.12'],
            ],
        ]);
        ServerDatabaseEngine::create([
            'server_id' => $server->id,
            'engine' => 'postgres',
            'version' => '17',
            'is_default' => true,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'name' => 'jobs-app',
            'runtime' => 'node',
            'runtime_version' => '22.1.0',
        ]);
        $site->domains()->create(['hostname' => 'jobs.example.com', 'is_primary' => true]);

        Artisan::call('dply:server:show', [
            'server' => $server->id,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame('prod-1', $decoded['name']);
        $this->assertSame('203.0.113.10', $decoded['ip_address']);
        $this->assertSame('8.4', $decoded['php_version']);
        $this->assertSame('22.1.0', $decoded['runtime_defaults']['node']);
        $this->assertCount(1, $decoded['database_engines']);
        $this->assertSame('postgres', $decoded['database_engines'][0]['engine']);
        $this->assertSame(1, $decoded['site_count']);
        $this->assertSame('jobs-app', $decoded['sites'][0]['name']);
        $this->assertSame('jobs.example.com', $decoded['sites'][0]['primary_hostname']);
    }

    public function test_resolves_by_name(): void
    {
        $server = Server::factory()->create(['name' => 'web-1']);

        Artisan::call('dply:server:show', [
            'server' => 'web-1',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame($server->id, $decoded['id']);
    }

    public function test_resolves_by_ip(): void
    {
        $server = Server::factory()->create(['ip_address' => '203.0.113.55']);

        Artisan::call('dply:server:show', [
            'server' => '203.0.113.55',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame($server->id, $decoded['id']);
    }

    public function test_human_output_renders_section_headings(): void
    {
        $server = Server::factory()->create([
            'name' => 'lonely-1',
            'meta' => ['php_version' => '8.4'],
        ]);

        $exit = Artisan::call('dply:server:show', ['server' => $server->id]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Server', $output);
        $this->assertStringContainsString('Runtimes', $output);
        $this->assertStringContainsString('Database engines', $output);
        $this->assertStringContainsString('Sites', $output);
    }

    public function test_zero_sites_renders_friendly_message(): void
    {
        $server = Server::factory()->create();

        Artisan::call('dply:server:show', ['server' => $server->id]);
        $output = Artisan::output();

        $this->assertStringContainsString('No sites hosted yet', $output);
    }

    public function test_command_fails_when_server_not_found(): void
    {
        $exit = Artisan::call('dply:server:show', ['server' => 'nope']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Server not found', $output);
    }
}
