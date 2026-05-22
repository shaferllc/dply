<?php

declare(strict_types=1);

namespace Tests\Feature\SiteDeploymentsListPageTest;
use App\Jobs\RunSiteDeploymentJob;
use App\Livewire\Sites\DeploymentsList;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('renders deployments newest first', function () {
    [$user, $server, $site] = makeUserSite();
    $older = seedDeploy($site, SiteDeployment::STATUS_SUCCESS, now()->subDays(2), 'manual');
    $newer = seedDeploy($site, SiteDeployment::STATUS_FAILED, now()->subHours(1), 'webhook');

    $response = $this->actingAs($user)->get(route('sites.deployments.index', [
        'server' => $server,
        'site' => $site,
    ]));

    $response->assertOk()
        ->assertSee('Deployments')
        ->assertSee($older->id)
        ->assertSee($newer->id);

    $body = (string) $response->getContent();

    // Newer should come before older in the rendered HTML.
    expect(strpos($body, $newer->id))->toBeLessThan(strpos($body, $older->id));
});
test('status filter narrows to failures', function () {
    [$user, $server, $site] = makeUserSite();
    $success = seedDeploy($site, SiteDeployment::STATUS_SUCCESS, now(), 'manual');
    $failure = seedDeploy($site, SiteDeployment::STATUS_FAILED, now(), 'manual');

    $response = $this->actingAs($user)->get(route('sites.deployments.index', [
        'server' => $server,
        'site' => $site,
    ]).'?status=failed');

    $response->assertOk()
        ->assertSee($failure->id)
        ->assertDontSee($success->id);
});
test('trigger filter narrows to one trigger', function () {
    [$user, $server, $site] = makeUserSite();
    $manual = seedDeploy($site, SiteDeployment::STATUS_SUCCESS, now(), 'manual');
    $webhook = seedDeploy($site, SiteDeployment::STATUS_SUCCESS, now(), 'webhook');

    $response = $this->actingAs($user)->get(route('sites.deployments.index', [
        'server' => $server,
        'site' => $site,
    ]).'?trigger=webhook');

    $response->assertOk()
        ->assertSee($webhook->id)
        ->assertDontSee($manual->id);
});
test('renders friendly message when no deployments match', function () {
    [$user, $server, $site] = makeUserSite();

    $response = $this->actingAs($user)->get(route('sites.deployments.index', [
        'server' => $server,
        'site' => $site,
    ]));

    $response->assertOk()
        ->assertSee('No deployments match');
});
test('aborts when user is not in org', function () {
    [, $server, $site] = makeUserSite();

    $stranger = User::factory()->create();
    $strangerOrg = Organization::factory()->create();
    $strangerOrg->users()->attach($stranger->id, ['role' => 'owner']);
    session(['current_organization_id' => $strangerOrg->id]);

    $response = $this->actingAs($stranger)->get(route('sites.deployments.index', [
        'server' => $server,
        'site' => $site,
    ]));

    $response->assertNotFound();
});
test('redeploy action queues a deployment', function () {
    Queue::fake();
    [$user, $server, $site] = makeUserSite();

    Livewire::actingAs($user)
        ->test(DeploymentsList::class, ['server' => $server, 'site' => $site])
        ->call('redeploy');

    Queue::assertPushed(RunSiteDeploymentJob::class, fn ($job): bool => $job->site->is($site));
});
test('serverless deployments tab embeds the journey with a redeploy control', function () {
    [$user, $server, $site] = makeFunctionsSite();

    Livewire::actingAs($user)
        ->test(DeploymentsList::class, ['server' => $server, 'site' => $site])
        // The embedded journey panel is the redeploy surface.
        ->assertSeeLivewire('serverless.journey')
        ->assertSee('Redeploy');
});
function makeFunctionsSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Site::STATUS_FUNCTIONS_ACTIVE,
    ]);

    return [$user, $server, $site];
}
function makeUserSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['webserver' => 'nginx'],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    return [$user, $server, $site];
}
function seedDeploy(Site $site, string $status, \DateTimeInterface $startedAt, string $trigger): SiteDeployment
{
    return SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'status' => $status,
        'trigger' => $trigger,
        'started_at' => $startedAt,
        'finished_at' => $startedAt,
    ]);
}
