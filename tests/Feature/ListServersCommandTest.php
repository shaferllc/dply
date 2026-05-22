<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ListServersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_lists_servers_with_runtime_engines_and_site_count(): void
    {
        $server = Server::factory()->create([
            'name' => 'edge-1',
            'meta' => [
                'php_version' => '8.4',
                'runtime_defaults' => ['node' => '22'],
            ],
        ]);
        ServerDatabaseEngine::create([
            'server_id' => $server->id,
            'engine' => 'postgres',
            'is_default' => true,
        ]);
        Site::factory()->count(3)->create(['server_id' => $server->id]);

        $exit = Artisan::call('dply:server:list');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('edge-1', $output);
        $this->assertStringContainsString('php 8.4', $output);
        $this->assertStringContainsString('node', $output);
        $this->assertStringContainsString('postgres*', $output);
        $this->assertStringContainsString('3', $output); // site count
    }

    public function test_ready_flag_filters_to_ready_servers(): void
    {
        Server::factory()->create(['name' => 'edge-ready', 'status' => Server::STATUS_READY]);
        Server::factory()->create(['name' => 'edge-pending', 'status' => Server::STATUS_PENDING]);

        $exit = Artisan::call('dply:server:list', ['--ready' => true, '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $names = array_column($decoded['servers'], 'name');
        $this->assertContains('edge-ready', $names);
        $this->assertNotContains('edge-pending', $names);
    }

    public function test_command_emits_json_with_full_data(): void
    {
        $server = Server::factory()->create([
            'name' => 'edge-1',
            'meta' => ['runtime_defaults' => ['python' => '3.12']],
        ]);
        Site::factory()->create(['server_id' => $server->id]);

        $exit = Artisan::call('dply:server:list', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertGreaterThan(0, $decoded['count']);
        $matched = collect($decoded['servers'])->firstWhere('name', 'edge-1');
        $this->assertNotNull($matched);
        $this->assertSame(['python'], $matched['runtimes']);
        $this->assertSame(1, $matched['site_count']);
    }

    public function test_command_handles_empty_state(): void
    {
        $exit = Artisan::call('dply:server:list');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No servers found', $output);
    }
}
