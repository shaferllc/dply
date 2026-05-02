<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FailedDeploysFleetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_sites_with_failed_latest_deploy(): void
    {
        $server = Server::factory()->create();
        $broken = Site::factory()->create(['server_id' => $server->id, 'name' => 'broken-app']);
        $healthy = Site::factory()->create(['server_id' => $server->id, 'name' => 'healthy-app']);
        // Broken: only failed deploy.
        $this->seedDeploy($broken, SiteDeployment::STATUS_FAILED, now()->subHour());
        // Healthy: success after a previous failure.
        $this->seedDeploy($healthy, SiteDeployment::STATUS_FAILED, now()->subDay());
        $this->seedDeploy($healthy, SiteDeployment::STATUS_SUCCESS, now()->subHour());

        $exit = Artisan::call('dply:fleet:failed-deploys', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertSame(1, $decoded['count']);
        $this->assertSame('broken-app', $decoded['sites'][0]['site_name']);
    }

    public function test_skips_running_deploys_by_default(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);
        // Latest is "running" — but the previous failed.
        $this->seedDeploy($site, SiteDeployment::STATUS_FAILED, now()->subHour());
        $this->seedDeploy($site, SiteDeployment::STATUS_RUNNING, now()->subMinutes(2));

        Artisan::call('dply:fleet:failed-deploys', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        // Running is skipped, so latest settled = failed → counted.
        $this->assertSame(1, $decoded['count']);
    }

    public function test_include_running_treats_running_as_latest(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);
        $this->seedDeploy($site, SiteDeployment::STATUS_FAILED, now()->subHour());
        $this->seedDeploy($site, SiteDeployment::STATUS_RUNNING, now()->subMinutes(2));

        Artisan::call('dply:fleet:failed-deploys', [
            '--include-running' => true,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        // Latest is now running, not failed.
        $this->assertSame(0, $decoded['count']);
    }

    public function test_zero_failures_returns_success_exit_code(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);
        $this->seedDeploy($site, SiteDeployment::STATUS_SUCCESS, now()->subHour());

        $exit = Artisan::call('dply:fleet:failed-deploys');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No sites', $output);
    }

    public function test_orders_most_recently_failed_first(): void
    {
        $server = Server::factory()->create();
        $a = Site::factory()->create(['server_id' => $server->id, 'name' => 'older-failure']);
        $b = Site::factory()->create(['server_id' => $server->id, 'name' => 'recent-failure']);
        $this->seedDeploy($a, SiteDeployment::STATUS_FAILED, now()->subDays(7));
        $this->seedDeploy($b, SiteDeployment::STATUS_FAILED, now()->subHour());

        Artisan::call('dply:fleet:failed-deploys', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame('recent-failure', $decoded['sites'][0]['site_name']);
        $this->assertSame('older-failure', $decoded['sites'][1]['site_name']);
    }

    public function test_includes_drill_in_hint_in_human_output(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);
        $this->seedDeploy($site, SiteDeployment::STATUS_FAILED, now()->subHour());

        Artisan::call('dply:fleet:failed-deploys');
        $output = Artisan::output();

        $this->assertStringContainsString('dply:site:show-deploy', $output);
    }

    private function seedDeploy(Site $site, string $status, \DateTimeInterface $startedAt): SiteDeployment
    {
        return SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'status' => $status,
            'trigger' => 'manual',
            'started_at' => $startedAt,
            'finished_at' => $status === SiteDeployment::STATUS_RUNNING ? null : $startedAt,
        ]);
    }
}
