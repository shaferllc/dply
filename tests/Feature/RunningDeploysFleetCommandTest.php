<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RunningDeploysFleetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_currently_running_deploys(): void
    {
        $server = Server::factory()->create();
        $a = Site::factory()->create(['server_id' => $server->id, 'name' => 'site-a']);
        $b = Site::factory()->create(['server_id' => $server->id, 'name' => 'site-b']);
        $this->seedDeploy($a, SiteDeployment::STATUS_RUNNING, now()->subMinutes(20));
        $this->seedDeploy($b, SiteDeployment::STATUS_RUNNING, now()->subMinutes(2));
        $this->seedDeploy($a, SiteDeployment::STATUS_SUCCESS, now()->subHours(2)); // ignored

        Artisan::call('dply:fleet:running-deploys', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(2, $decoded['count']);
        // Sorted by started_at ascending — so site-a (older) comes first.
        $this->assertSame('site-a', $decoded['deployments'][0]['site_name']);
        $this->assertSame('site-b', $decoded['deployments'][1]['site_name']);
    }

    public function test_excludes_settled_deploys(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);
        $this->seedDeploy($site, SiteDeployment::STATUS_SUCCESS, now()->subMinutes(1));
        $this->seedDeploy($site, SiteDeployment::STATUS_FAILED, now()->subMinutes(2));

        Artisan::call('dply:fleet:running-deploys', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $decoded['count']);
    }

    public function test_older_than_filter(): void
    {
        $server = Server::factory()->create();
        $a = Site::factory()->create(['server_id' => $server->id, 'name' => 'old-running']);
        $b = Site::factory()->create(['server_id' => $server->id, 'name' => 'fresh-running']);
        $this->seedDeploy($a, SiteDeployment::STATUS_RUNNING, now()->subMinutes(30));
        $this->seedDeploy($b, SiteDeployment::STATUS_RUNNING, now()->subMinutes(2));

        Artisan::call('dply:fleet:running-deploys', [
            '--older-than' => 15,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(1, $decoded['count']);
        $this->assertSame('old-running', $decoded['deployments'][0]['site_name']);
    }

    public function test_includes_deploy_id_for_drill_in(): void
    {
        $server = Server::factory()->create();
        $site = Site::factory()->create(['server_id' => $server->id]);
        $deploy = $this->seedDeploy($site, SiteDeployment::STATUS_RUNNING, now()->subMinutes(5));

        Artisan::call('dply:fleet:running-deploys', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame($deploy->id, $decoded['deployments'][0]['deployment_id']);
    }

    public function test_friendly_message_when_nothing_running(): void
    {
        $exit = Artisan::call('dply:fleet:running-deploys');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No deploys are currently running', $output);
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
