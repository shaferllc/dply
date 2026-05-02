<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SitePsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_lists_processes_for_a_site_by_slug(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'slug' => 'jobs-app',
            'name' => 'Jobs App',
            'runtime' => 'node',
            'runtime_version' => '22.7.0',
            'internal_port' => 30005,
        ]);

        // The Site::created hook auto-creates a `web` row; backfill its
        // command and add a worker so the table has variety.
        $site->processes()->where('type', SiteProcess::TYPE_WEB)
            ->update(['command' => 'npm start']);
        $site->processes()->create([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'worker',
            'command' => 'npm run worker',
            'scale' => 1,
            'is_active' => true,
        ]);

        $exit = Artisan::call('dply:ps', ['site' => 'jobs-app']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Jobs App', $output);
        $this->assertStringContainsString('node@22.7.0', $output);
        $this->assertStringContainsString('30005', $output);
        $this->assertStringContainsString('npm start', $output);
        $this->assertStringContainsString('npm run worker', $output);
    }

    public function test_command_resolves_site_by_id(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'slug' => 'svc',
            'runtime' => 'python',
        ]);

        $exit = Artisan::call('dply:ps', ['site' => $site->id]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString($site->slug, $output);
    }

    public function test_command_returns_failure_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:ps', ['site' => 'nonexistent-slug']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No site found', $output);
    }

    public function test_command_emits_machine_readable_json_with_flag(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'slug' => 'svc',
            'name' => 'Svc',
            'runtime' => 'go',
            'runtime_version' => '1.22',
            'internal_port' => 30009,
        ]);
        $site->processes()->where('type', SiteProcess::TYPE_WEB)
            ->update(['command' => './bin/app']);

        $exit = Artisan::call('dply:ps', ['site' => 'svc', '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame('go', $decoded['site']['runtime']);
        $this->assertSame('1.22', $decoded['site']['runtime_version']);
        $this->assertSame(30009, $decoded['site']['internal_port']);
        $this->assertNotEmpty($decoded['processes']);
        $this->assertSame('web', $decoded['processes'][0]['type']);
        $this->assertSame('./bin/app', $decoded['processes'][0]['command']);
    }

    public function test_command_orders_processes_web_first_then_worker_then_scheduler(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'slug' => 'queue-app',
        ]);

        // Create out of canonical order to verify the SQL ordering.
        $site->processes()->create([
            'type' => SiteProcess::TYPE_SCHEDULER,
            'name' => 'scheduler',
            'command' => 'php artisan schedule:work',
        ]);
        $site->processes()->create([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'horizon',
            'command' => 'php artisan horizon',
        ]);

        $exit = Artisan::call('dply:ps', ['site' => 'queue-app', '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $types = array_column($decoded['processes'], 'type');

        $this->assertSame(['web', 'worker', 'scheduler'], $types);
    }
}
