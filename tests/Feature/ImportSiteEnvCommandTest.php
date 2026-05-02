<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteEnvironmentVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ImportSiteEnvCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_merge_mode_creates_and_updates_without_removing(): void
    {
        $site = $this->makeSite();
        SiteEnvironmentVariable::query()->create([
            'site_id' => $site->id,
            'env_key' => 'KEEP_ME',
            'env_value' => 'k',
            'environment' => 'production',
        ]);
        SiteEnvironmentVariable::query()->create([
            'site_id' => $site->id,
            'env_key' => 'OVERRIDE_ME',
            'env_value' => 'old',
            'environment' => 'production',
        ]);

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

        $rows = SiteEnvironmentVariable::query()->where('site_id', $site->id)->get();
        $this->assertCount(3, $rows);
        $this->assertSame('new', $rows->firstWhere('env_key', 'OVERRIDE_ME')->env_value);
        $this->assertSame('k', $rows->firstWhere('env_key', 'KEEP_ME')->env_value);
        $this->assertSame('fresh', $rows->firstWhere('env_key', 'NEW_ONE')->env_value);
    }

    public function test_replace_mode_removes_keys_not_in_file(): void
    {
        $site = $this->makeSite();
        SiteEnvironmentVariable::query()->create([
            'site_id' => $site->id,
            'env_key' => 'GOING_AWAY',
            'env_value' => 'g',
            'environment' => 'production',
        ]);

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

        $remaining = SiteEnvironmentVariable::query()->where('site_id', $site->id)->pluck('env_key')->all();
        $this->assertSame(['KEPT'], $remaining);
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
        $this->assertSame(0, SiteEnvironmentVariable::query()->where('site_id', $site->id)->count());
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
        $this->assertSame(1, SiteEnvironmentVariable::query()->where('site_id', $site->id)->count());
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

    public function test_environment_flag_scopes_writes_and_replaces(): void
    {
        $site = $this->makeSite();
        SiteEnvironmentVariable::query()->create([
            'site_id' => $site->id,
            'env_key' => 'PROD_KEY',
            'env_value' => 'p',
            'environment' => 'production',
        ]);

        $file = $this->writeEnvFile("STAGING_KEY=s\n");

        Artisan::call('dply:site:env-import', [
            'site' => $site->slug,
            '--file' => $file,
            '--environment' => 'staging',
            '--replace' => true,
            '--json' => true,
        ]);

        // Production left alone, staging populated.
        $prod = SiteEnvironmentVariable::query()
            ->where('site_id', $site->id)
            ->where('environment', 'production')
            ->pluck('env_key')->all();
        $staging = SiteEnvironmentVariable::query()
            ->where('site_id', $site->id)
            ->where('environment', 'staging')
            ->pluck('env_key')->all();

        $this->assertSame(['PROD_KEY'], $prod);
        $this->assertSame(['STAGING_KEY'], $staging);
    }

    private function makeSite(): Site
    {
        $server = Server::factory()->create();

        return Site::factory()->create([
            'server_id' => $server->id,
            'slug' => 'jobs',
        ]);
    }

    private function writeEnvFile(string $contents): string
    {
        $path = sys_get_temp_dir().'/dply-env-import-'.uniqid().'.env';
        file_put_contents($path, $contents);

        return $path;
    }
}
