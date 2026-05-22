<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class StaleDeploysFleetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_sites_with_old_deploys(): void
    {
        $server = Server::factory()->create();
        $stale = Site::factory()->create(['server_id' => $server->id, 'name' => 'old-app']);
        $fresh = Site::factory()->create(['server_id' => $server->id, 'name' => 'fresh-app']);
        $this->seedDeployment($stale, now()->subDays(60));
        $this->seedDeployment($fresh, now()->subDays(5));

        Artisan::call('dply:fleet:stale-deploys', [
            '--days' => 30,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(1, $decoded['count']);
        $this->assertSame('old-app', $decoded['sites'][0]['site_name']);
        $this->assertGreaterThanOrEqual(60, $decoded['sites'][0]['age_days']);
    }

    public function test_excludes_never_deployed_by_default(): void
    {
        $server = Server::factory()->create();
        Site::factory()->create(['server_id' => $server->id, 'name' => 'never-deployed']);

        Artisan::call('dply:fleet:stale-deploys', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $decoded['count']);
    }

    public function test_include_never_flag_adds_undeployed_sites(): void
    {
        $server = Server::factory()->create();
        Site::factory()->create(['server_id' => $server->id, 'name' => 'never-deployed']);

        Artisan::call('dply:fleet:stale-deploys', [
            '--include-never' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(1, $decoded['count']);
        $this->assertNull($decoded['sites'][0]['last_deploy_at']);
        $this->assertNull($decoded['sites'][0]['age_days']);
    }

    public function test_only_successful_deploys_count(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);
        // Failed deploy 5 days ago, no successful ever — should be treated as never-deployed.
        SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'status' => SiteDeployment::STATUS_FAILED,
            'trigger' => 'manual',
            'started_at' => now()->subDays(5),
            'finished_at' => now()->subDays(5),
        ]);

        Artisan::call('dply:fleet:stale-deploys', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $decoded['count']);

        // With --include-never, this site should appear because it has
        // no successful deploys.
        Artisan::call('dply:fleet:stale-deploys', [
            '--include-never' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertSame(1, $decoded['count']);
    }

    public function test_oldest_first_ordering(): void
    {
        $server = Server::factory()->create();
        $a = Site::factory()->create(['server_id' => $server->id, 'name' => 'older-app']);
        $b = Site::factory()->create(['server_id' => $server->id, 'name' => 'newer-app']);
        $this->seedDeployment($a, now()->subDays(120));
        $this->seedDeployment($b, now()->subDays(45));

        Artisan::call('dply:fleet:stale-deploys', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame('older-app', $decoded['sites'][0]['site_name']);
        $this->assertSame('newer-app', $decoded['sites'][1]['site_name']);
    }

    public function test_human_friendly_message_when_nothing_stale(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);
        $this->seedDeployment($site, now()->subDays(2));

        $exit = Artisan::call('dply:fleet:stale-deploys');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No stale sites', $output);
    }

    private function seedDeployment(Site $site, \DateTimeInterface $finishedAt): SiteDeployment
    {
        return SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'status' => SiteDeployment::STATUS_SUCCESS,
            'trigger' => 'manual',
            'started_at' => $finishedAt,
            'finished_at' => $finishedAt,
        ]);
    }
}
