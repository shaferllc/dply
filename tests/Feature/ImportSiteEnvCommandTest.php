<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ImportSiteEnvCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_merge_mode_creates_and_updates_without_removing(): void
    {
        $site = $this->makeSite(['env_file_content' => "KEEP_ME=k\nOVERRIDE_ME=old"]);

        $file = $this->writeEnvFile("OVERRIDE_ME=new\nNEW_ONE=fresh\n");

        $exit = Artisan::call('dply:site:env-import', [
            'site' => $site->slug,
            '--file' => $file,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('merge', $decoded['mode']);
        $this->assertSame(['NEW_ONE'], $decoded['created']);
        $this->assertSame(['OVERRIDE_ME'], $decoded['updated']);
        $this->assertSame([], $decoded['removed']);

        $this->assertSame([
            'KEEP_ME' => 'k',
            'NEW_ONE' => 'fresh',
            'OVERRIDE_ME' => 'new',
        ], $this->parsed($site->fresh()));
    }

    public function test_replace_mode_removes_keys_not_in_file(): void
    {
        $site = $this->makeSite(['env_file_content' => 'GOING_AWAY=g']);

        $file = $this->writeEnvFile("KEPT=ok\n");

        Artisan::call('dply:site:env-import', [
            'site' => $site->slug,
            '--file' => $file,
            '--replace' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame('replace', $decoded['mode']);
        $this->assertSame(['GOING_AWAY'], $decoded['removed']);

        $this->assertSame(['KEPT' => 'ok'], $this->parsed($site->fresh()));
    }

    public function test_dry_run_does_not_write(): void
    {
        $site = $this->makeSite();
        $file = $this->writeEnvFile("FRESH=val\n");

        Artisan::call('dply:site:env-import', [
            'site' => $site->slug,
            '--file' => $file,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertTrue($decoded['dry_run']);
        $this->assertSame(['FRESH'], $decoded['created']);
        $this->assertSame([], $this->parsed($site->fresh()));
    }

    public function test_command_reports_parse_errors(): void
    {
        $site = $this->makeSite();
        $file = $this->writeEnvFile("MALFORMED_LINE\nGOOD=value\n");

        Artisan::call('dply:site:env-import', [
            'site' => $site->slug,
            '--file' => $file,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(['GOOD'], $decoded['created']);
        $this->assertCount(1, $decoded['errors']);
        $this->assertSame(['GOOD' => 'value'], $this->parsed($site->fresh()));
    }

    public function test_command_fails_when_file_missing(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:env-import', [
            'site' => $site->slug,
            '--file' => '/tmp/dply-nonexistent-'.uniqid().'.env',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not found', $output);
    }

    public function test_command_fails_when_file_option_missing(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:env-import', ['site' => $site->slug]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--file is required', $output);
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

    private function writeEnvFile(string $contents): string
    {
        $path = sys_get_temp_dir().'/dply-env-import-'.uniqid().'.env';
        file_put_contents($path, $contents);

        return $path;
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
