<?php

namespace Tests\Feature\Api\SiteDeployApiTest;

use App\Jobs\RunSiteDeploymentJob;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
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
