<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteEnvironmentVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SetSiteEnvCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_sets_a_new_environment_variable(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:env-set', [
            'site' => $site->slug,
            'assignment' => 'API_KEY=secret',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Set API_KEY', $output);

        $row = SiteEnvironmentVariable::query()
            ->where('site_id', $site->id)
            ->where('env_key', 'API_KEY')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('secret', $row->env_value);
        $this->assertSame('production', $row->environment);
    }

    public function test_command_updates_existing_variable_in_place(): void
    {
        $site = $this->makeSite();
        SiteEnvironmentVariable::query()->create([
            'site_id' => $site->id,
            'env_key' => 'API_KEY',
            'env_value' => 'old',
            'environment' => 'production',
        ]);

        Artisan::call('dply:site:env-set', [
            'site' => $site->slug,
            'assignment' => 'API_KEY=new',
        ]);

        $this->assertSame(1, SiteEnvironmentVariable::query()->where('site_id', $site->id)->count());
        $this->assertSame('new', SiteEnvironmentVariable::query()->where('site_id', $site->id)->first()->env_value);
    }

    public function test_unset_flag_removes_variable(): void
    {
        $site = $this->makeSite();
        SiteEnvironmentVariable::query()->create([
            'site_id' => $site->id,
            'env_key' => 'API_KEY',
            'env_value' => 'something',
            'environment' => 'production',
        ]);

        $exit = Artisan::call('dply:site:env-set', [
            'site' => $site->slug,
            'assignment' => 'API_KEY=',
            '--unset' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(0, SiteEnvironmentVariable::query()->where('site_id', $site->id)->count());
    }

    public function test_unset_is_a_noop_when_variable_was_not_set(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:env-set', [
            'site' => $site->slug,
            'assignment' => 'API_KEY=',
            '--unset' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('was not set', $output);
    }

    public function test_environment_flag_scopes_writes(): void
    {
        $site = $this->makeSite();

        Artisan::call('dply:site:env-set', [
            'site' => $site->slug,
            'assignment' => 'API_KEY=staging-secret',
            '--environment' => 'staging',
        ]);
        Artisan::call('dply:site:env-set', [
            'site' => $site->slug,
            'assignment' => 'API_KEY=prod-secret',
        ]);

        $rows = SiteEnvironmentVariable::query()->where('site_id', $site->id)->get();
        $this->assertCount(2, $rows);
        $this->assertSame('staging-secret', $rows->firstWhere('environment', 'staging')->env_value);
        $this->assertSame('prod-secret', $rows->firstWhere('environment', 'production')->env_value);
    }

    public function test_command_rejects_invalid_assignment_format(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:env-set', [
            'site' => $site->slug,
            'assignment' => 'no-equal-sign',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('KEY=VALUE', $output);
    }

    public function test_command_rejects_invalid_key_pattern(): void
    {
        $site = $this->makeSite();

        $exit = Artisan::call('dply:site:env-set', [
            'site' => $site->slug,
            'assignment' => 'lowercase-key=foo',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('KEY must match', $output);
    }

    public function test_command_fails_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:site:env-set', [
            'site' => 'nope',
            'assignment' => 'X=y',
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
}
