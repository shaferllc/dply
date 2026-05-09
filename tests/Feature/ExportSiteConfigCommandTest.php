<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\ExportSiteConfigCommand;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ExportSiteConfigCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_exports_runtime_processes_domains_to_stdout(): void
    {
        $site = $this->makeSite();
        $site->domains()->create(['hostname' => 'jobs.example.com', 'is_primary' => true]);

        Artisan::call('dply:site:export-config', ['site' => $site->slug]);
        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);

        $this->assertSame(ExportSiteConfigCommand::FORMAT_VERSION, $decoded['format_version']);
        $this->assertSame($site->id, $decoded['site']['id']);
        $this->assertSame('node', $decoded['site']['runtime']);
        $this->assertNotEmpty($decoded['domains']);
        $this->assertSame('jobs.example.com', $decoded['domains'][0]['hostname']);
    }

    public function test_masks_env_values_by_default(): void
    {
        $site = $this->makeSite(['env_file_content' => 'API_KEY=super-secret']);

        Artisan::call('dply:site:export-config', ['site' => $site->slug]);
        $output = Artisan::output();
        $decoded = json_decode(trim($output), true);

        $this->assertFalse($decoded['with_secrets']);
        $this->assertStringNotContainsString('super-secret', $output);
        $this->assertSame('***', $decoded['environment_variables'][0]['value']);
    }

    public function test_with_secrets_writes_cleartext(): void
    {
        $site = $this->makeSite(['env_file_content' => 'API_KEY=super-secret']);

        Artisan::call('dply:site:export-config', [
            'site' => $site->slug,
            '--with-secrets' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertTrue($decoded['with_secrets']);
        $this->assertSame('super-secret', $decoded['environment_variables'][0]['value']);
    }

    public function test_exports_processes(): void
    {
        $site = $this->makeSite();
        // Site::created hook makes a 'web' process; add a worker.
        $site->processes()->create([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'queue',
            'command' => 'node worker.js',
            'scale' => 2,
            'is_active' => true,
        ]);

        Artisan::call('dply:site:export-config', ['site' => $site->slug]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $names = array_column($decoded['processes'], 'name');
        $this->assertContains('queue', $names);
        $queueProcess = collect($decoded['processes'])->firstWhere('name', 'queue');
        $this->assertSame(2, $queueProcess['scale']);
    }

    public function test_writes_to_file_with_to_option(): void
    {
        $site = $this->makeSite();
        $path = sys_get_temp_dir().'/dply-config-'.uniqid().'.json';

        Artisan::call('dply:site:export-config', [
            'site' => $site->slug,
            '--to' => $path,
        ]);

        $this->assertFileExists($path);
        $this->assertNotEmpty(file_get_contents($path));
        unlink($path);
    }

    public function test_refuses_to_overwrite_without_force(): void
    {
        $site = $this->makeSite();
        $path = sys_get_temp_dir().'/dply-config-'.uniqid().'.json';
        file_put_contents($path, 'preexisting');

        $exit = Artisan::call('dply:site:export-config', [
            'site' => $site->slug,
            '--to' => $path,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Refusing to overwrite', $output);
        $this->assertSame('preexisting', file_get_contents($path));
        unlink($path);
    }

    public function test_command_fails_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:site:export-config', ['site' => 'nope']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Site not found', $output);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeSite(array $attrs = []): Site
    {
        $server = Server::factory()->create();

        return Site::factory()->create(array_merge([
            'server_id' => $server->id,
            'slug' => 'jobs',
            'runtime' => 'node',
            'runtime_version' => '20.10.0',
        ], $attrs));
    }
}
