<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SetSiteRuntimeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_updates_runtime_and_version(): void
    {
        $site = $this->makeSite(['runtime' => 'php', 'runtime_version' => '8.2']);

        $exit = Artisan::call('dply:site:set-runtime', [
            'site' => $site->slug,
            '--runtime' => 'node',
            '--runtime-version' => '20.10.0',
        ]);

        $this->assertSame(0, $exit);
        $site->refresh();
        $this->assertSame('node', $site->runtime);
        $this->assertSame('20.10.0', $site->runtime_version);
    }

    public function test_command_updates_build_start_port(): void
    {
        $site = $this->makeSite(['runtime' => 'node']);

        Artisan::call('dply:site:set-runtime', [
            'site' => $site->slug,
            '--build' => 'npm run build',
            '--start' => 'node server.js',
            '--port' => '3000',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertFalse($decoded['dry_run']);
        $this->assertArrayHasKey('build_command', $decoded['changes']);
        $this->assertArrayHasKey('start_command', $decoded['changes']);
        $this->assertArrayHasKey('internal_port', $decoded['changes']);

        $site->refresh();
        $this->assertSame('npm run build', $site->build_command);
        $this->assertSame('node server.js', $site->start_command);
        $this->assertSame(3000, $site->internal_port);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $site = $this->makeSite(['runtime' => 'php']);

        Artisan::call('dply:site:set-runtime', [
            'site' => $site->slug,
            '--runtime' => 'node',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertTrue($decoded['dry_run']);
        $this->assertSame('php', $site->fresh()->runtime);
    }

    public function test_unset_engine_clears_database_engine(): void
    {
        $site = $this->makeSite(['runtime' => 'static', 'database_engine' => 'postgres']);

        Artisan::call('dply:site:set-runtime', [
            'site' => $site->slug,
            '--unset-engine' => true,
        ]);

        $this->assertNull($site->fresh()->database_engine);
    }

    public function test_engine_and_unset_engine_are_mutually_exclusive(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:set-runtime', [
            'site' => $site->slug,
            '--engine' => 'mysql',
            '--unset-engine' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('mutually exclusive', $output);
    }

    public function test_command_rejects_unknown_runtime(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:set-runtime', [
            'site' => $site->slug,
            '--runtime' => 'cobol',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown runtime', $output);
    }

    public function test_command_rejects_invalid_port(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:set-runtime', [
            'site' => $site->slug,
            '--port' => '99999',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invalid port', $output);
    }

    public function test_command_fails_when_no_changes_requested(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:set-runtime', ['site' => $site->slug]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No changes requested', $output);
    }

    public function test_command_fails_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:site:set-runtime', [
            'site' => 'nope',
            '--runtime' => 'node',
        ]);
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
