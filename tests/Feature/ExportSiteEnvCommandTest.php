<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ExportSiteEnvCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_to_stdout_by_default(): void
    {
        $site = $this->makeSite(['env_file_content' => 'API_KEY=super-secret']);

        $exit = Artisan::call('dply:site:env-export', ['site' => $site->slug]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('API_KEY=super-secret', $output);
    }

    public function test_writes_to_file_with_to_option(): void
    {
        $site = $this->makeSite(['env_file_content' => 'A=1']);

        $path = sys_get_temp_dir().'/dply-export-'.uniqid().'.env';
        $exit = Artisan::call('dply:site:env-export', [
            'site' => $site->slug,
            '--to' => $path,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertFileExists($path);
        $this->assertStringContainsString('A=1', file_get_contents($path));
        $this->assertStringContainsString('Exported 1 variable', $output);

        unlink($path);
    }

    public function test_refuses_to_overwrite_without_force(): void
    {
        $site = $this->makeSite(['env_file_content' => 'A=1']);

        $path = sys_get_temp_dir().'/dply-export-'.uniqid().'.env';
        file_put_contents($path, 'pre-existing');

        $exit = Artisan::call('dply:site:env-export', [
            'site' => $site->slug,
            '--to' => $path,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Refusing to overwrite', $output);
        $this->assertSame('pre-existing', file_get_contents($path));

        unlink($path);
    }

    public function test_force_overwrites_existing(): void
    {
        $site = $this->makeSite(['env_file_content' => 'A=1']);

        $path = sys_get_temp_dir().'/dply-export-'.uniqid().'.env';
        file_put_contents($path, 'pre-existing');

        $exit = Artisan::call('dply:site:env-export', [
            'site' => $site->slug,
            '--to' => $path,
            '--force' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame("A=1\n", file_get_contents($path));

        unlink($path);
    }

    public function test_command_fails_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:site:env-export', ['site' => 'nope']);
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
