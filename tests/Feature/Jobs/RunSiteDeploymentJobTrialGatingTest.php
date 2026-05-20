<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RunSiteDeploymentJob;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunSiteDeploymentJobTrialGatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_skipped_deployment_recorded_when_org_trial_expired(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create(['trial_ends_at' => now()->subDays(5)]);
        $project = Project::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'name' => 'Test Project',
            'slug' => 'test-project',
            'kind' => Project::KIND_BYO_SITE,
        ]);
        $server = Server::factory()->create([
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
        ]);
        $site = Site::factory()->create([
            'organization_id' => $org->id,
            'server_id' => $server->id,
            'project_id' => $project->id,
        ]);

        RunSiteDeploymentJob::dispatchSync($site->fresh(), SiteDeployment::TRIGGER_MANUAL);

        $deployment = SiteDeployment::query()->where('site_id', $site->id)->latest()->firstOrFail();

        $this->assertSame(SiteDeployment::STATUS_SKIPPED, $deployment->status);
        $this->assertStringContainsString('Deploys are paused', (string) $deployment->log_output);
        $this->assertStringContainsString('trial', (string) $deployment->log_output);
    }

    public function test_deploy_proceeds_when_org_is_on_active_trial(): void
    {
        $org = Organization::factory()->create(['trial_ends_at' => now()->addDays(7)]);
        $this->assertTrue($org->canDeploy());
    }
}
