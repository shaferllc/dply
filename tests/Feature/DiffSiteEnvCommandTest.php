<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteEnvironmentVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DiffSiteEnvCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_in_sync_when_envs_match(): void
    {
        $site = $this->makeSite();
        $this->seedVar($site, 'A', 'one', 'production');
        $this->seedVar($site, 'A', 'one', 'staging');

        Artisan::call('dply:site:env-diff', [
            'site' => $site->slug,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertTrue($decoded['in_sync']);
        $this->assertSame([], $decoded['only_in_from']);
        $this->assertSame([], $decoded['only_in_to']);
        $this->assertSame([], $decoded['differs']);
    }

    public function test_categorizes_only_in_from_only_in_to_and_differs(): void
    {
        $site = $this->makeSite();
        $this->seedVar($site, 'PROD_ONLY', 'p', 'production');
        $this->seedVar($site, 'SHARED', 'prod-value', 'production');
        $this->seedVar($site, 'IDENTICAL', 'same', 'production');

        $this->seedVar($site, 'STAGING_ONLY', 's', 'staging');
        $this->seedVar($site, 'SHARED', 'staging-value', 'staging');
        $this->seedVar($site, 'IDENTICAL', 'same', 'staging');

        Artisan::call('dply:site:env-diff', [
            'site' => $site->slug,
            '--reveal' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertFalse($decoded['in_sync']);
        $this->assertSame(['PROD_ONLY'], $decoded['only_in_from']);
        $this->assertSame(['STAGING_ONLY'], $decoded['only_in_to']);
        $this->assertSame(['SHARED'], array_keys($decoded['differs']));
        $this->assertSame('prod-value', $decoded['differs']['SHARED']['from']);
        $this->assertSame('staging-value', $decoded['differs']['SHARED']['to']);
    }

    public function test_masks_values_in_differs_by_default(): void
    {
        $site = $this->makeSite();
        $this->seedVar($site, 'API_KEY', 'super-prod-secret', 'production');
        $this->seedVar($site, 'API_KEY', 'super-stage-secret', 'staging');

        Artisan::call('dply:site:env-diff', [
            'site' => $site->slug,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertStringNotContainsString('super-prod-secret', json_encode($decoded));
        $this->assertStringNotContainsString('super-stage-secret', json_encode($decoded));
        $this->assertStringContainsString('•', $decoded['differs']['API_KEY']['from']);
    }

    public function test_custom_from_and_to_options(): void
    {
        $site = $this->makeSite();
        $this->seedVar($site, 'A', 'p', 'production');
        $this->seedVar($site, 'B', 's', 'staging');
        $this->seedVar($site, 'C', 'd', 'development');

        Artisan::call('dply:site:env-diff', [
            'site' => $site->slug,
            '--from' => 'staging',
            '--to' => 'development',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame('staging', $decoded['from']);
        $this->assertSame('development', $decoded['to']);
        $this->assertSame(['B'], $decoded['only_in_from']);
        $this->assertSame(['C'], $decoded['only_in_to']);
    }

    public function test_rejects_same_from_and_to(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:env-diff', [
            'site' => $site->slug,
            '--from' => 'production',
            '--to' => 'production',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('must differ', $output);
    }

    public function test_human_renders_in_sync_when_no_drift(): void
    {
        $site = $this->makeSite();

        Artisan::call('dply:site:env-diff', ['site' => $site->slug]);
        $output = Artisan::output();

        $this->assertStringContainsString('in sync', $output);
    }

    public function test_command_fails_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:site:env-diff', ['site' => 'nope']);
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
