<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureVmPlatformEnabled;
use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use App\Support\ProductLine\ProductLineKillSwitches;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::getFacadeRoot()->except([RunSiteDeploymentJob::class]);
});

test('vm kill switch blocks server create routes', function () {
    config(['features.global.vm_enabled' => false]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $this->actingAs($user)
        ->get(route('servers.create'))
        ->assertStatus(503);
});

test('vm kill switch skips vm site deploy job', function () {
    config(['features.global.vm_enabled' => false]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
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

    expect(ProductLineKillSwitches::blocksVmSiteDeploy($site))->toBeTrue();

    RunSiteDeploymentJob::dispatchSync($site->fresh(), SiteDeployment::TRIGGER_MANUAL);

    $deployment = SiteDeployment::query()->where('site_id', $site->id)->latest()->firstOrFail();
    expect($deployment->status)->toBe(SiteDeployment::STATUS_SKIPPED);
    expect($deployment->log_output)->toContain('VM deploys are temporarily disabled');
});

test('ensure vm platform middleware passes when enabled', function () {
    config(['features.global.vm_enabled' => true]);

    $middleware = new EnsureVmPlatformEnabled;
    $response = $middleware->handle(Request::create('/servers/create', 'GET'), fn () => response('ok'));

    expect($response->getContent())->toBe('ok');
});
