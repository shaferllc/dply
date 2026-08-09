<?php

namespace Tests\Feature\Jobs\ServerlessFirstDeployFailureTest;

use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Modules\Deploy\Services\DeployRepoPreflight;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);
usesFeatures('surface.serverless');

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeServerlessSite(string $status = Site::STATUS_FUNCTIONS_CONFIGURED, ?\DateTimeInterface $lastDeployAt = null): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'meta' => [
            'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            'digitalocean_functions' => [
                'api_host' => 'https://faas.example',
                'namespace' => 'ns',
                'access_key' => 'uuid:key',
            ],
        ],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => $status,
        'git_repository_url' => 'acme/laravel-demo',
        'git_branch' => 'main',
        'last_deploy_at' => $lastDeployAt,
        'meta' => [
            'runtime_profile' => 'digitalocean_functions_web',
            'serverless' => ['runtime' => 'php:8.4', 'entrypoint' => 'main'],
        ],
    ]);

    return [$user, $server, $site];
}

test('first serverless deploy preflight failure marks the site functions_failed', function () {
    Queue::getFacadeRoot()->except([RunSiteDeploymentJob::class]);

    [, , $site] = makeServerlessSite(Site::STATUS_FUNCTIONS_CONFIGURED);

    $this->mock(DeployRepoPreflight::class, function ($mock): void {
        $mock->shouldReceive('check')->andReturn('Repository preflight failed — the Git host rejected the connection.');
    });

    try {
        RunSiteDeploymentJob::dispatchSync($site->fresh(), SiteDeployment::TRIGGER_MANUAL);
    } catch (\Throwable) {
        // Job rethrows after recording failure — expected.
    }

    expect($site->fresh()->status)->toBe(Site::STATUS_FUNCTIONS_FAILED);
    expect(SiteDeployment::query()->where('site_id', $site->id)->latest()->value('status'))
        ->toBe(SiteDeployment::STATUS_FAILED);
});

test('redeploy failure on an already-live function leaves status functions_active', function () {
    Queue::getFacadeRoot()->except([RunSiteDeploymentJob::class]);

    [, , $site] = makeServerlessSite(Site::STATUS_FUNCTIONS_ACTIVE, now()->subHour());

    $this->mock(DeployRepoPreflight::class, function ($mock): void {
        $mock->shouldReceive('check')->andReturn('Repository preflight failed — the Git host rejected the connection.');
    });

    try {
        RunSiteDeploymentJob::dispatchSync($site->fresh(), SiteDeployment::TRIGGER_MANUAL);
    } catch (\Throwable) {
        // expected
    }

    expect($site->fresh()->status)->toBe(Site::STATUS_FUNCTIONS_ACTIVE);
});

test('never-live failed serverless site workspace redirects to the deploy journey', function () {
    [$user, $server, $site] = makeServerlessSite(Site::STATUS_FUNCTIONS_FAILED);

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site]))
        ->assertRedirect(route('serverless.journey', ['server' => $server, 'site' => $site]));
});

test('live serverless site workspace stays on sites.show', function () {
    [$user, $server, $site] = makeServerlessSite(Site::STATUS_FUNCTIONS_ACTIVE, now()->subHour());

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site]))
        ->assertOk();
});
