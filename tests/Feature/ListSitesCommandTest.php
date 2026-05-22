<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ListSitesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_lists_all_sites_with_runtime_info(): void
    {
        $server = Server::factory()->create(['name' => 'edge-1']);
        Site::factory()->create([
            'server_id' => $server->id,
            'name' => 'jobs',
            'runtime' => 'node',
            'runtime_version' => '22',
            'internal_port' => 30001,
        ]);
        Site::factory()->create([
            'server_id' => $server->id,
            'name' => 'shop',
            'runtime' => 'php',
            'runtime_version' => '8.4',
        ]);

        $exit = Artisan::call('dply:site:list');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('jobs', $output);
        $this->assertStringContainsString('node 22', $output);
        $this->assertStringContainsString('shop', $output);
        $this->assertStringContainsString('php 8.4', $output);
        $this->assertStringContainsString('30001', $output);
    }

    public function test_command_filters_by_runtime(): void
    {
        $server = Server::factory()->create();
        Site::factory()->create(['server_id' => $server->id, 'name' => 'jobs', 'runtime' => 'node']);
        Site::factory()->create(['server_id' => $server->id, 'name' => 'shop', 'runtime' => 'php']);

        $exit = Artisan::call('dply:site:list', ['--runtime' => 'node', '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertSame(1, $decoded['count']);
        $this->assertSame('jobs', $decoded['sites'][0]['name']);
    }

    public function test_command_filters_by_server(): void
    {
        $a = Server::factory()->create(['name' => 'edge-a']);
        $b = Server::factory()->create(['name' => 'edge-b']);
        Site::factory()->create(['server_id' => $a->id, 'name' => 'on-a']);
        Site::factory()->create(['server_id' => $b->id, 'name' => 'on-b']);

        $exit = Artisan::call('dply:site:list', ['--server' => 'edge-a', '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertSame(1, $decoded['count']);
        $this->assertSame('on-a', $decoded['sites'][0]['name']);
    }

    public function test_command_fails_for_unknown_server(): void
    {
        $exit = Artisan::call('dply:site:list', ['--server' => 'no-such']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Server not found', $output);
    }

    public function test_command_reports_no_match_when_filter_empty(): void
    {
        $server = Server::factory()->create();
        Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);

        $exit = Artisan::call('dply:site:list', ['--runtime' => 'node']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No sites match', $output);
    }

    public function test_limit_clamps_results(): void
    {
        $server = Server::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            Site::factory()->create(['server_id' => $server->id, 'name' => "s{$i}"]);
        }

        $exit = Artisan::call('dply:site:list', ['--limit' => 2, '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertSame(2, $decoded['count']);
    }
}
