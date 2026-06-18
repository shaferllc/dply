<?php

namespace Tests\Feature\Jobs\RunSiteDeploymentJobTrialGatingTest;

use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::getFacadeRoot()->except([RunSiteDeploymentJob::class]);
});

test('skipped deployment recorded when org trial expired', function () {
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

    expect($deployment->status)->toBe(SiteDeployment::STATUS_SKIPPED);
    $this->assertStringContainsString('Deploys are paused', (string) $deployment->log_output);
    $this->assertStringContainsString('trial', (string) $deployment->log_output);
});

test('deploy proceeds when org is on active trial', function () {
    $org = Organization::factory()->create(['trial_ends_at' => now()->addDays(7)]);
    expect($org->canDeploy())->toBeTrue();
});
