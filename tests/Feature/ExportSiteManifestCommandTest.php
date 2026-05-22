<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ExportSiteManifestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_manifest_to_stdout(): void
    {
        $site = $this->makeSite(['runtime' => 'node', 'runtime_version' => '20']);

        $exit = Artisan::call('dply:site:export-manifest', ['site' => $site->slug]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('runtime: node', $output);
        $this->assertStringContainsString("version: '20'", $output);
    }

    public function test_writes_manifest_to_file_with_to(): void
    {
        $site = $this->makeSite(['runtime' => 'php']);

        $path = sys_get_temp_dir().'/dply-manifest-'.uniqid().'.yaml';
        $exit = Artisan::call('dply:site:export-manifest', [
            'site' => $site->slug,
            '--to' => $path,
        ]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($path);
        $this->assertStringContainsString('runtime: php', file_get_contents($path));

        unlink($path);
    }

    public function test_refuses_to_overwrite_without_force(): void
    {
        $site = $this->makeSite(['runtime' => 'php']);

        $path = sys_get_temp_dir().'/dply-manifest-'.uniqid().'.yaml';
        file_put_contents($path, 'preexisting');

        $exit = Artisan::call('dply:site:export-manifest', [
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
        $exit = Artisan::call('dply:site:export-manifest', ['site' => 'nope']);
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
        ], $attrs));
    }
}
