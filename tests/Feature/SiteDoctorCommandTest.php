<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SiteEnvironmentVariable;
use App\Models\SiteProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SiteDoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_report_contains_runtime_database_processes_envcounts(): void
    {
        $server = Server::factory()->create();
        ServerDatabaseEngine::create([
            'server_id' => $server->id,
            'engine' => 'postgres',
            'is_default' => true,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'runtime' => 'node',
            'runtime_version' => '20.10.0',
            'build_command' => 'npm run build',
            'start_command' => 'node server.js',
            'internal_port' => 3000,
            'database_engine' => 'postgres',
        ]);
        // Site::created hook auto-creates a 'web' process. Tweak it
        // to scale=2 and add an inactive worker alongside it.
        $site->processes()->where('name', 'web')->update(['scale' => 2, 'is_active' => true]);
        SiteProcess::factory()->create([
            'site_id' => $site->id,
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'queue',
            'is_active' => false,
            'scale' => 1,
        ]);
        SiteEnvironmentVariable::query()->create([
            'site_id' => $site->id,
            'env_key' => 'A',
            'env_value' => 'x',
            'environment' => 'production',
        ]);

        Artisan::call('dply:site:doctor', [
            'site' => $site->slug,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame('node', $decoded['runtime']['key']);
        $this->assertSame('20.10.0', $decoded['runtime']['version']);
        $this->assertSame(3000, $decoded['runtime']['internal_port']);
        $this->assertSame('postgres', $decoded['database']['engine']);
        $this->assertTrue($decoded['database']['server_has_engine']);
        $this->assertSame(2, $decoded['processes']['total']);
        $this->assertSame(1, $decoded['processes']['active']);
        $this->assertSame(2, $decoded['processes']['total_scale']);
        $this->assertSame(1, $decoded['env_var_counts']['production']);
        $this->assertSame([], $decoded['drift']);
    }

    public function test_drift_reports_unregistered_database_engine(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'runtime' => 'php',
            'database_engine' => 'mysql',
        ]);

        Artisan::call('dply:site:doctor', [
            'site' => $site->slug,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertFalse($decoded['database']['server_has_engine']);
        $this->assertNotEmpty($decoded['drift']);
        $this->assertStringContainsString('mysql', $decoded['drift'][0]);
    }

    public function test_latest_deployment_summary_appears_when_present(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);
        $deployment = SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'status' => 'success',
            'trigger' => 'manual',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
            'phase_results' => [
                'build' => ['ok' => true, 'steps' => []],
                'release' => ['ok' => true, 'steps' => []],
            ],
        ]);

        Artisan::call('dply:site:doctor', [
            'site' => $site->slug,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertNotNull($decoded['latest_deployment']);
        $this->assertSame($deployment->id, $decoded['latest_deployment']['id']);
        $this->assertSame(['build', 'release'], $decoded['latest_deployment']['phases_recorded']);
    }

    public function test_latest_deployment_is_null_when_none_exists(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);

        Artisan::call('dply:site:doctor', [
            'site' => $site->slug,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertNull($decoded['latest_deployment']);
    }

    public function test_human_output_renders_section_headings(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id, 'runtime' => 'php']);

        $exit = Artisan::call('dply:site:doctor', ['site' => $site->slug]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Site doctor for', $output);
        $this->assertStringContainsString('Runtime', $output);
        $this->assertStringContainsString('Database', $output);
        $this->assertStringContainsString('Processes', $output);
        $this->assertStringContainsString('Latest deployment', $output);
        $this->assertStringContainsString('Environment variables', $output);
    }

    public function test_command_fails_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:site:doctor', ['site' => 'nope']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Site not found', $output);
    }
}
