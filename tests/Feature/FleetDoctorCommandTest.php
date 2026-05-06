<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FleetDoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_fleet_returns_zero_with_no_drift_message(): void
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
        Site::factory()->create([
            'server_id' => $server->id,
            'runtime' => 'node',
            'database_engine' => 'postgres',
        ]);

        $exit = Artisan::call('dply:fleet:doctor');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No drift detected', $output);
    }

    public function test_drift_returns_failure_with_per_server_breakdown(): void
    {
        $clean = Server::factory()->create(['name' => 'clean']);
        $dirty = Server::factory()->create([
            'name' => 'dirty',
            'meta' => ['runtime_defaults' => ['node' => '22']],
        ]);
        ServerDatabaseEngine::create([
            'server_id' => $dirty->id,
            'engine' => 'postgres',
            'is_default' => true,
        ]);
        // Site on dirty pins an engine NOT registered.
        Site::factory()->create([
            'server_id' => $dirty->id,
            'name' => 'orphan',
            'database_engine' => 'mariadb',
        ]);
        // Site on dirty uses a runtime not in runtime_defaults.
        Site::factory()->create([
            'server_id' => $dirty->id,
            'name' => 'pyapp',
            'runtime' => 'python',
        ]);

        $exit = Artisan::call('dply:fleet:doctor');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('dirty', $output);
        $this->assertStringNotContainsString('clean ', $output); // clean server isn't in the drift table
    }

    public function test_command_emits_json_with_totals_and_per_server(): void
    {
        $server = Server::factory()->create([
            'name' => 'edge-1',
            'meta' => ['runtime_defaults' => ['node' => '22']],
        ]);
        Site::factory()->create([
            'server_id' => $server->id,
            'runtime' => 'python',
        ]);

        $exit = Artisan::call('dply:fleet:doctor', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['totals']['servers_checked']);
        $this->assertSame(1, $decoded['totals']['servers_with_drift']);
        $this->assertSame(1, $decoded['totals']['sites_needing_runtime_install']);
        $this->assertCount(1, $decoded['servers']);
    }

    public function test_ready_flag_excludes_pending_servers(): void
    {
        Server::factory()->create(['name' => 'edge-ready', 'status' => Server::STATUS_READY]);
        Server::factory()->create(['name' => 'edge-pending', 'status' => Server::STATUS_PENDING]);

        $exit = Artisan::call('dply:fleet:doctor', ['--ready' => true, '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $names = array_column($decoded['servers'], 'server_name');
        $this->assertContains('edge-ready', $names);
        $this->assertNotContains('edge-pending', $names);
    }

    public function test_totals_include_running_and_long_running_deploys(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);
        // Two running deploys: one fresh, one long-running.
        SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'status' => SiteDeployment::STATUS_RUNNING,
            'trigger' => 'manual',
            'started_at' => now()->subMinutes(2),
        ]);
        SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'status' => SiteDeployment::STATUS_RUNNING,
            'trigger' => 'manual',
            'started_at' => now()->subMinutes(30),
        ]);

        Artisan::call('dply:fleet:doctor', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(2, $decoded['totals']['running_deploys']);
        $this->assertSame(1, $decoded['totals']['long_running_deploys']);
    }

    public function test_failed_latest_count_reflects_unrecovered_failures(): void
    {
        $server = Server::factory()->create();
        $broken = Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);
        $recovered = Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);
        SiteDeployment::query()->create([
            'site_id' => $broken->id,
            'project_id' => $broken->project_id,
            'status' => SiteDeployment::STATUS_FAILED,
            'trigger' => 'manual',
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour(),
        ]);
        SiteDeployment::query()->create([
            'site_id' => $recovered->id,
            'project_id' => $recovered->project_id,
            'status' => SiteDeployment::STATUS_FAILED,
            'trigger' => 'manual',
            'started_at' => now()->subDay(),
            'finished_at' => now()->subDay(),
        ]);
        SiteDeployment::query()->create([
            'site_id' => $recovered->id,
            'project_id' => $recovered->project_id,
            'status' => SiteDeployment::STATUS_SUCCESS,
            'trigger' => 'manual',
            'started_at' => now()->subHour(),
            'finished_at' => now()->subHour(),
        ]);

        $exit = Artisan::call('dply:fleet:doctor', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertSame(1, $decoded['totals']['sites_with_failed_latest_deploy']);
    }
}
