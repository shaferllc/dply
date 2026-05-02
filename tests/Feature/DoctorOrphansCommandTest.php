<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SiteDomain;
use App\Models\SiteEnvironmentVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DoctorOrphansCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_fleet_returns_zero_orphans(): void
    {
        $server = Server::factory()->create();
        Site::factory()->create(['server_id' => $server->id]);

        $exit = Artisan::call('dply:doctor:orphans', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $decoded['total_orphans']);
    }

    public function test_detects_orphaned_environment_variables(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);
        $orphanVar = SiteEnvironmentVariable::query()->create([
            'site_id' => $site->id,
            'env_key' => 'A',
            'env_value' => '1',
            'environment' => 'production',
        ]);

        // Drop the FK so DELETE on sites doesn't cascade — simulates the
        // buggy-data state this command exists to detect.
        DB::statement('ALTER TABLE site_environment_variables DROP CONSTRAINT IF EXISTS site_environment_variables_site_id_foreign');
        DB::table('sites')->where('id', $site->id)->delete();

        $exit = Artisan::call('dply:doctor:orphans', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertContains($orphanVar->id, $decoded['orphans']['site_environment_variables']);
    }

    public function test_detects_orphaned_domains(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);
        $domain = $site->domains()->create(['hostname' => 'example.com']);
        DB::statement('ALTER TABLE site_domains DROP CONSTRAINT IF EXISTS site_domains_site_id_foreign');
        DB::table('sites')->where('id', $site->id)->delete();

        Artisan::call('dply:doctor:orphans', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertContains($domain->id, $decoded['orphans']['site_domains']);
    }

    public function test_detects_orphaned_deployments(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);
        $deploy = SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'status' => SiteDeployment::STATUS_SUCCESS,
            'trigger' => 'manual',
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        DB::statement('ALTER TABLE site_deployments DROP CONSTRAINT IF EXISTS site_deployments_site_id_foreign');
        DB::table('sites')->where('id', $site->id)->delete();

        Artisan::call('dply:doctor:orphans', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertContains($deploy->id, $decoded['orphans']['site_deployments']);
    }

    public function test_prune_requires_force(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);
        SiteEnvironmentVariable::query()->create([
            'site_id' => $site->id,
            'env_key' => 'A',
            'env_value' => '1',
            'environment' => 'production',
        ]);
        DB::statement('ALTER TABLE site_environment_variables DROP CONSTRAINT IF EXISTS site_environment_variables_site_id_foreign');
        DB::table('sites')->where('id', $site->id)->delete();

        $exit = Artisan::call('dply:doctor:orphans', ['--prune' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('requires --force', $output);
    }

    public function test_prune_with_force_actually_deletes(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);
        SiteEnvironmentVariable::query()->create([
            'site_id' => $site->id,
            'env_key' => 'A',
            'env_value' => '1',
            'environment' => 'production',
        ]);
        DB::statement('ALTER TABLE site_environment_variables DROP CONSTRAINT IF EXISTS site_environment_variables_site_id_foreign');
        DB::table('sites')->where('id', $site->id)->delete();

        Artisan::call('dply:doctor:orphans', [
            '--prune' => true,
            '--force' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertTrue($decoded['pruned']);
        $this->assertGreaterThanOrEqual(1, $decoded['deleted']);
        $this->assertSame(0, SiteEnvironmentVariable::query()->count());
    }

    public function test_human_output_friendly_when_clean(): void
    {
        $server = Server::factory()->create();
        Site::factory()->create(['server_id' => $server->id]);

        Artisan::call('dply:doctor:orphans');
        $output = Artisan::output();

        $this->assertStringContainsString('No orphans detected', $output);
    }
}
