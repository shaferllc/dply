<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AbortSiteDeployCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_aborts_latest_running_deployment(): void
    {
        $site = $this->makeSite();
        $deployment = $this->seedDeployment($site, SiteDeployment::STATUS_RUNNING, now()->subHour());

        $exit = Artisan::call('dply:site:abort-deploy', [
            'site' => $site->slug,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame($deployment->id, $decoded['deployment_id']);
        $this->assertSame('failed', $decoded['new_status']);
        $deployment->refresh();
        $this->assertSame('failed', $deployment->status);
        $this->assertNotNull($deployment->finished_at);
    }

    public function test_aborts_specific_deployment_by_id(): void
    {
        $site = $this->makeSite();
        $older = $this->seedDeployment($site, SiteDeployment::STATUS_RUNNING, now()->subDay());
        $newer = $this->seedDeployment($site, SiteDeployment::STATUS_RUNNING, now()->subHour());

        Artisan::call('dply:site:abort-deploy', [
            'site' => $site->slug,
            '--id' => $older->id,
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame($older->id, $decoded['deployment_id']);
        $this->assertSame('failed', $older->fresh()->status);
        $this->assertSame('running', $newer->fresh()->status);
    }

    public function test_refuses_to_abort_recent_deploy_without_force(): void
    {
        $site = $this->makeSite();
        $deployment = $this->seedDeployment($site, SiteDeployment::STATUS_RUNNING, now()->subMinutes(2));

        $exit = Artisan::call('dply:site:abort-deploy', ['site' => $site->slug]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('only', $output);
        $this->assertSame('running', $deployment->fresh()->status);
    }

    public function test_force_overrides_age_guard(): void
    {
        $site = $this->makeSite();
        $deployment = $this->seedDeployment($site, SiteDeployment::STATUS_RUNNING, now()->subSeconds(30));

        $exit = Artisan::call('dply:site:abort-deploy', [
            'site' => $site->slug,
            '--force' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('failed', $deployment->fresh()->status);
    }

    public function test_refuses_to_abort_already_succeeded_deployment(): void
    {
        $site = $this->makeSite();
        $deployment = $this->seedDeployment($site, SiteDeployment::STATUS_SUCCESS, now()->subHour());

        $exit = Artisan::call('dply:site:abort-deploy', [
            'site' => $site->slug,
            '--id' => $deployment->id,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not "running"', $output);
    }

    public function test_no_running_deployments_returns_failure(): void
    {
        $site = $this->makeSite();
        $this->seedDeployment($site, SiteDeployment::STATUS_SUCCESS, now()->subHour());

        $exit = Artisan::call('dply:site:abort-deploy', ['site' => $site->slug]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No running deployments', $output);
    }

    public function test_command_fails_when_site_not_found(): void
    {
        $exit = Artisan::call('dply:site:abort-deploy', ['site' => 'nope']);
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

    private function seedDeployment(Site $site, string $status, \DateTimeInterface $startedAt): SiteDeployment
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
