<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FleetSummaryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_aggregates_runtime_counts_across_sites(): void
    {
        $server = Server::factory()->create(['status' => Server::STATUS_READY]);
        Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);
        Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);
        Site::factory()->create(['server_id' => $server->id, 'runtime' => 'node']);
        Site::factory()->create(['server_id' => $server->id, 'runtime' => 'python']);
        ServerDatabaseEngine::create([
            'server_id' => $server->id,
            'engine' => 'postgres',
            'is_default' => true,
        ]);

        $exit = Artisan::call('dply:fleet:summary', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertSame(1, $decoded['totals']['servers']);
        $this->assertSame(4, $decoded['totals']['sites']);
        $this->assertSame(2, $decoded['site_runtimes']['php']);
        $this->assertSame(1, $decoded['site_runtimes']['node']);
        $this->assertSame(1, $decoded['site_runtimes']['python']);
        $this->assertSame(1, $decoded['engine_usage']['postgres']);
    }

    public function test_command_renders_human_table(): void
    {
        $server = Server::factory()->create(['status' => Server::STATUS_READY]);
        Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);

        $exit = Artisan::call('dply:fleet:summary');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Fleet summary', $output);
        $this->assertStringContainsString('Servers by status', $output);
        $this->assertStringContainsString('Sites by runtime', $output);
        $this->assertStringContainsString('php', $output);
    }

    public function test_command_handles_empty_fleet(): void
    {
        $exit = Artisan::call('dply:fleet:summary', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertSame(0, $decoded['totals']['servers']);
        $this->assertSame(0, $decoded['totals']['sites']);
        $this->assertSame([], $decoded['site_runtimes']);
        $this->assertSame([], $decoded['engine_usage']);
    }

    public function test_command_groups_unset_runtime_under_unset_key(): void
    {
        $server = Server::factory()->create();
        Site::factory()->create(['server_id' => $server->id, 'runtime' => null]);
        Site::factory()->create(['server_id' => $server->id, 'runtime' => 'go']);

        $exit = Artisan::call('dply:fleet:summary', ['--json' => true]);
        $output = Artisan::output();

        $decoded = json_decode($output, true);
        $this->assertSame(1, $decoded['site_runtimes']['unset']);
        $this->assertSame(1, $decoded['site_runtimes']['go']);
    }
}
