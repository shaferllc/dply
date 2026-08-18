<?php

namespace Tests\Feature\Api\SiteDeployApiTest;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Modules\Deploy\Services\DeployRepoPreflight;
use App\Services\Sites\SiteGitDeployer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;

uses(RefreshDatabase::class);

function makeSiteInOrg(Organization $org): Site
{
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'git_repository_url' => 'git@github.com:org/repo.git',
    ]);
}

/**
 * @return array{0: User, 1: Organization, 2: string}
 */
function createTokenForOrg(Organization $org, ?array $abilities = null, ?array $allowedIps = null): array
{
    $user = $org->users()->first();
    if (! $user) {
        $user = User::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
    }
    ['plaintext' => $plain] = ApiToken::createToken(
        $user,
        $org,
        'test',
        null,
        $abilities ?? ['sites.read', 'sites.deploy', 'servers.read'],
        $allowedIps
    );

    return [$user, $org, $plain];
}

/**
 * @return array{0: Workspace, 1: Site}
 */
function makeProjectSite(Organization $org, User $owner): array
{
    $workspace = Workspace::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $owner->id,
    ]);
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $owner->id,
        'workspace_id' => $workspace->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $owner->id,
        'workspace_id' => $workspace->id,
        'git_repository_url' => 'git@github.com:org/restricted.git',
    ]);

    return [$workspace, $site];
}

function tokenForUser(User $user, Organization $org, array $abilities): string
{
    ['plaintext' => $plain] = ApiToken::createToken(
        $user,
        $org,
        'test',
        null,
        $abilities,
    );

    return $plain;
}

test('deployments list returns 403 for site in other org', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $siteA = makeSiteInOrg($orgA);
    [, , $plainB] = createTokenForOrg($orgB);

    $this->getJson('/api/v1/sites/'.$siteA->id.'/deployments', [
        'Authorization' => 'Bearer '.$plainB,
    ])->assertForbidden();
});

test('idempotent deploy returns 409 while inflight', function () {
    Queue::fake();
    $org = Organization::factory()->create();
    $site = makeSiteInOrg($org);
    [, , $plain] = createTokenForOrg($org);

    $headers = [
        'Authorization' => 'Bearer '.$plain,
        'Idempotency-Key' => 'same-key',
    ];

    $this->postJson('/api/v1/sites/'.$site->id.'/deploy', [], $headers)->assertAccepted();
    $this->postJson('/api/v1/sites/'.$site->id.'/deploy', [], $headers)->assertStatus(409);

    Queue::assertPushed(RunSiteDeploymentJob::class, 1);
});

test('sync deploy with idempotency key caches result', function () {
    Queue::getFacadeRoot()->except([RunSiteDeploymentJob::class]);

    $this->mock(DeployRepoPreflight::class, function ($mock) {
        $mock->shouldReceive('check')->andReturn(null);
    });

    $this->mock(SiteGitDeployer::class, function ($mock) {
        $mock->shouldReceive('run')->once()->andReturn(['output' => 'ok', 'sha' => 'abc123']);
    });

    $org = Organization::factory()->create();
    $site = makeSiteInOrg($org);
    [, , $plain] = createTokenForOrg($org);

    $headers = [
        'Authorization' => 'Bearer '.$plain,
        'Idempotency-Key' => 'idem-1',
    ];

    $r1 = $this->postJson('/api/v1/sites/'.$site->id.'/deploy', ['sync' => true], $headers);
    $r1->assertOk();
    $r2 = $this->postJson('/api/v1/sites/'.$site->id.'/deploy', ['sync' => true], $headers);
    $r2->assertOk();
    expect($r2->json())->toBe($r1->json());
});

test('org deployer cannot list or deploy a project-restricted site', function () {
    Queue::fake();

    $org = Organization::factory()->create();
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);

    [, $site] = makeProjectSite($org, $owner);
    $openSite = makeSiteInOrg($org);

    $deployer = User::factory()->create();
    $org->users()->attach($deployer->id, ['role' => 'deployer']);
    $plain = tokenForUser($deployer, $org, ['sites.read', 'sites.deploy']);

    $headers = ['Authorization' => 'Bearer '.$plain];

    $index = $this->getJson('/api/v1/sites', $headers)->assertOk();
    $ids = collect($index->json('data'))->pluck('id');
    expect($ids)->toContain($openSite->id)->not->toContain($site->id);

    $this->postJson('/api/v1/sites/'.$site->id.'/deploy', [], $headers)->assertForbidden();
    Queue::assertNotPushed(RunSiteDeploymentJob::class);

    $deployment = SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => SiteDeployment::TRIGGER_API,
        'status' => SiteDeployment::STATUS_SUCCESS,
        'log_output' => 'APP_KEY=base64:secret-from-deploy',
    ]);

    $this->getJson('/api/v1/sites/'.$site->id.'/deployments', $headers)->assertForbidden();
    $this->getJson('/api/v1/sites/'.$site->id.'/deployments/'.$deployment->id, $headers)->assertForbidden();
});

test('project deployer can queue a deploy on a restricted site', function () {
    Queue::fake();

    $org = Organization::factory()->create();
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);

    [$workspace, $site] = makeProjectSite($org, $owner);

    $projectDeployer = User::factory()->create();
    $org->users()->attach($projectDeployer->id, ['role' => 'member']);
    $workspace->members()->create([
        'user_id' => $projectDeployer->id,
        'role' => WorkspaceMember::ROLE_DEPLOYER,
    ]);
    $plain = tokenForUser($projectDeployer, $org, ['sites.deploy']);

    $this->postJson('/api/v1/sites/'.$site->id.'/deploy', [], [
        'Authorization' => 'Bearer '.$plain,
    ])->assertAccepted();

    Queue::assertPushed(RunSiteDeploymentJob::class, 1);
});

test('project viewer can list a restricted site but cannot deploy', function () {
    Queue::fake();

    $org = Organization::factory()->create();
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);

    [$workspace, $site] = makeProjectSite($org, $owner);

    $viewer = User::factory()->create();
    $org->users()->attach($viewer->id, ['role' => 'member']);
    $workspace->members()->create([
        'user_id' => $viewer->id,
        'role' => WorkspaceMember::ROLE_VIEWER,
    ]);
    $plain = tokenForUser($viewer, $org, ['sites.read', 'sites.deploy']);

    $headers = ['Authorization' => 'Bearer '.$plain];

    $index = $this->getJson('/api/v1/sites', $headers)->assertOk();
    expect(collect($index->json('data'))->pluck('id'))->toContain($site->id);

    $this->postJson('/api/v1/sites/'.$site->id.'/deploy', [], $headers)->assertForbidden();
    Queue::assertNotPushed(RunSiteDeploymentJob::class);
});

test('api token ip allow list blocks wrong ip', function () {
    $org = Organization::factory()->create();
    makeSiteInOrg($org);
    [, , $plain] = createTokenForOrg($org, ['sites.read'], ['198.51.100.10']);

    $this->getJson('/api/v1/sites', [
        'Authorization' => 'Bearer '.$plain,
    ])->assertForbidden();
});

afterEach(function () {
    Mockery::close();
});
