<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteEnvironmentVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ClearSiteEnvCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_clears_all_vars_in_scope_with_force(): void
    {
        $site = $this->makeSite();
        $this->seedVar($site, 'A', 'a', 'production');
        $this->seedVar($site, 'B', 'b', 'production');

        $exit = Artisan::call('dply:site:env-clear', [
            'site' => $site->slug,
            '--force' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(2, $decoded['deleted']);
        $this->assertSame(['A', 'B'], $decoded['keys']);
        $this->assertSame(0, SiteEnvironmentVariable::query()->where('site_id', $site->id)->count());
    }

    public function test_refuses_without_force(): void
    {
        $site = $this->makeSite();
        $this->seedVar($site, 'A', 'a', 'production');

        $exit = Artisan::call('dply:site:env-clear', ['site' => $site->slug]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Refusing', $output);
        $this->assertSame(1, SiteEnvironmentVariable::query()->where('site_id', $site->id)->count());
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        $site = $this->makeSite();
        $this->seedVar($site, 'A', 'a', 'production');
        $this->seedVar($site, 'B', 'b', 'production');

        Artisan::call('dply:site:env-clear', [
            'site' => $site->slug,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertTrue($decoded['dry_run']);
        $this->assertSame(2, $decoded['count']);
        $this->assertSame(0, $decoded['deleted']);
        $this->assertSame(2, SiteEnvironmentVariable::query()->where('site_id', $site->id)->count());
    }

    public function test_environment_scopes_the_clear(): void
    {
        $site = $this->makeSite();
        $this->seedVar($site, 'A', 'a', 'production');
        $this->seedVar($site, 'B', 'b', 'staging');

        Artisan::call('dply:site:env-clear', [
            'site' => $site->slug,
            '--environment' => 'staging',
            '--force' => true,
        ]);

        $this->assertSame(1, SiteEnvironmentVariable::query()->where('site_id', $site->id)->count());
        $this->assertSame('A', SiteEnvironmentVariable::query()->where('site_id', $site->id)->first()->env_key);
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

    private function makeSite(): Site
    {
        $server = Server::factory()->create();

        return Site::factory()->create([
            'server_id' => $server->id,
            'slug' => 'jobs',
        ]);
    }

    private function seedVar(Site $site, string $key, string $value, string $environment): void
    {
        SiteEnvironmentVariable::query()->create([
            'site_id' => $site->id,
            'env_key' => $key,
            'env_value' => $value,
            'environment' => $environment,
        ]);
    }
}
