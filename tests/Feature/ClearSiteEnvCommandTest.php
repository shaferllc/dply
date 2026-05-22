<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ClearSiteEnvCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_clears_all_vars_with_force(): void
    {
        $site = $this->makeSite(['env_file_content' => "A=a\nB=b"]);

        $exit = Artisan::call('dply:site:env-clear', [
            'site' => $site->slug,
            '--force' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(2, $decoded['deleted']);
        $this->assertSame(['A', 'B'], $decoded['keys']);
        $this->assertSame([], $this->parsed($site->fresh()));
    }

    public function test_refuses_without_force(): void
    {
        $site = $this->makeSite(['env_file_content' => 'A=a']);

        $exit = Artisan::call('dply:site:env-clear', ['site' => $site->slug]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Refusing', $output);
        $this->assertSame(['A' => 'a'], $this->parsed($site->fresh()));
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        $site = $this->makeSite(['env_file_content' => "A=a\nB=b"]);

        Artisan::call('dply:site:env-clear', [
            'site' => $site->slug,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertTrue($decoded['dry_run']);
        $this->assertSame(2, $decoded['count']);
        $this->assertSame(0, $decoded['deleted']);
        $this->assertSame(['A' => 'a', 'B' => 'b'], $this->parsed($site->fresh()));
    }

    public function test_clear_when_already_empty_is_idempotent(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:env-clear', [
            'site' => $site->slug,
            '--force' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $decoded['deleted']);
    }

    public function test_command_fails_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:site:env-clear', [
            'site' => 'nope',
            '--force' => true,
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

    /**
     * @return array<string, string>
     */
    private function parsed(Site $site): array
    {
        $vars = app(DotEnvFileParser::class)->parse((string) ($site->env_file_content ?? ''))['variables'];
        ksort($vars);

        return $vars;
    }
}
