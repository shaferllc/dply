<?php

declare(strict_types=1);

namespace Tests\Feature\SiteDeploymentsListPageTest;

use App\Livewire\Sites\DeploymentsList;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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

    // History is the filterable list; Deploy is the live pipeline hub.
    $response = $this->actingAs($user)->get(route('sites.deployments.index', [
        'server' => $server,
        'site' => $site,
        'tab' => 'history',
        'status' => 'failed',
    ]));

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
        'tab' => 'history',
        'trigger' => 'webhook',
    ]));

    $response->assertOk()
        ->assertSee($webhook->id)
        ->assertDontSee($manual->id);
});
test('renders friendly message when no deployments match', function () {
    [$user, $server, $site] = makeUserSite();

    $response = $this->actingAs($user)->get(route('sites.deployments.index', [
        'server' => $server,
        'site' => $site,
        'tab' => 'history',
    ]));

    $response->assertOk()
        ->assertSee('No deployments yet');
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
        ->assertSee(__('Sync'))
        ->assertSee(__('Quick deploy'))
        ->assertSee(__('History'))
        // The embedded journey panel is the redeploy surface.
        ->assertSeeLivewire('serverless.journey')
        ->assertSee('Redeploy');
});

test('serverless deployments hub opens sync quick deploy and history', function () {
    [$user, $server, $site] = makeFunctionsSite();
    $site->update(['git_repository_url' => 'https://github.com/acme/laravel-demo']);
    $ran = seedDeploy($site, SiteDeployment::STATUS_SUCCESS, now(), 'manual');

    $component = Livewire::actingAs($user)
        ->test(DeploymentsList::class, ['server' => $server, 'site' => $site]);

    $component
        ->call('setTab', DeploymentsList::TAB_SYNC)
        ->assertSet('tab', DeploymentsList::TAB_SYNC)
        ->assertSee(__('Sync deploy'))
        ->assertDontSeeLivewire('serverless.journey');

    $component
        ->call('setTab', DeploymentsList::TAB_WEBHOOK)
        ->assertSet('tab', DeploymentsList::TAB_WEBHOOK)
        ->assertSeeLivewire('sites.repository');

    $component
        ->call('setTab', DeploymentsList::TAB_HISTORY)
        ->assertSet('tab', DeploymentsList::TAB_HISTORY)
        ->assertSee($ran->id)
        ->assertSee(route('serverless.deployments.show', ['site' => $site, 'deployment' => $ran]), false);
});

test('serverless overview shows function status and recent deploys', function () {
    [$user, $server, $site] = makeFunctionsSite();
    seedDeploy($site, SiteDeployment::STATUS_SUCCESS, now(), 'webhook');

    Livewire::actingAs($user)
        ->test(DeploymentsList::class, ['server' => $server, 'site' => $site])
        ->call('setTab', DeploymentsList::TAB_OVERVIEW)
        ->assertSee(__('Function'))
        ->assertSee(__('Recent deploys'))
        ->assertSee(__('Full history'));
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
        'meta' => ['runtime_profile' => 'digitalocean_functions_web'],
    ]);

    return [$user, $server, $site];
}
test('vm site gets the recurring Schedule tab', function () {
    [$user, $server, $site] = makeUserSite();

    Livewire::actingAs($user)
        ->test(DeploymentsList::class, ['server' => $server, 'site' => $site])
        ->assertSet('tab', DeploymentsList::TAB_DEPLOY)
        ->call('setTab', DeploymentsList::TAB_SCHEDULE)
        ->assertSet('tab', DeploymentsList::TAB_SCHEDULE)
        ->assertSee(__('Recurring deploys'));
});

test('functions site cannot reach the Schedule tab', function () {
    // RunDueDeploymentSchedulesCommand skips functions + edge runtimes, so a
    // schedule created here would never fire — the tab must not be reachable.
    [$user, $server, $site] = makeFunctionsSite();

    Livewire::actingAs($user)
        ->test(DeploymentsList::class, ['server' => $server, 'site' => $site])
        ->call('setTab', DeploymentsList::TAB_SCHEDULE)
        ->assertNotSet('tab', DeploymentsList::TAB_SCHEDULE);
});

test('direct ?tab=schedule url falls back to Deploy on a functions site', function () {
    [$user, $server, $site] = makeFunctionsSite();

    Livewire::actingAs($user)
        ->withQueryParams(['tab' => DeploymentsList::TAB_SCHEDULE])
        ->test(DeploymentsList::class, ['server' => $server, 'site' => $site])
        ->assertSet('tab', DeploymentsList::TAB_DEPLOY);
});

test('deploy tab labels the one-off delay "Deploy later", not Schedule', function () {
    // Two different features used to both read "Schedule" on this tab.
    [$user, $server, $site] = makeUserSite();

    Livewire::actingAs($user)
        ->test(DeploymentsList::class, ['server' => $server, 'site' => $site])
        ->assertSee(__('Deploy later'))
        ->assertDontSee(__('Scheduled deploys'));
});

test('skeleton and loaded tab rows agree for a functions site', function () {
    // The placeholder used to hand-maintain its own tab list, so the row shifted
    // when the real render landed (Pipeline flashed in, Releases popped in late).
    [$user, $server, $site] = makeFunctionsSite();

    $component = Livewire::actingAs($user)
        ->test(DeploymentsList::class, ['server' => $server, 'site' => $site])
        ->instance();

    $visible = $component->tabVisibility();
    $skeleton = array_column(array_values(array_filter(
        $component->tabDefinitions(),
        fn (array $e): bool => $visible[$e['id']] ?? true,
    )), 'id');

    expect($skeleton)->not->toContain(DeploymentsList::TAB_PIPELINE);
    expect($skeleton)->not->toContain(DeploymentsList::TAB_SCHEDULE);
    expect($skeleton)->not->toContain(DeploymentsList::TAB_RELEASES);
    expect($skeleton)->toContain(DeploymentsList::TAB_DEPLOY);
});

test('vm atomic site keeps pipeline and releases in both rows', function () {
    [$user, $server, $site] = makeUserSite();
    $site->update(['deploy_strategy' => 'atomic']);

    $component = Livewire::actingAs($user)
        ->test(DeploymentsList::class, ['server' => $server, 'site' => $site->fresh()])
        ->instance();

    $visible = $component->tabVisibility();

    expect($visible[DeploymentsList::TAB_PIPELINE])->toBeTrue();
    expect($visible[DeploymentsList::TAB_SCHEDULE])->toBeTrue();
    expect($visible[DeploymentsList::TAB_RELEASES])->toBeTrue();
});

test('deletes a single finished deployment', function () {
    [$user, $server, $site] = makeUserSite();
    $failed = seedDeploy($site, SiteDeployment::STATUS_FAILED, now(), 'manual');
    $kept = seedDeploy($site, SiteDeployment::STATUS_SUCCESS, now()->subHour(), 'manual');

    Livewire::actingAs($user)
        ->test(DeploymentsList::class, ['server' => $server, 'site' => $site])
        ->call('confirmDeleteDeployment', $failed->id)
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'deleteDeployment')
        ->call('confirmActionModal');

    expect(SiteDeployment::query()->find($failed->id))->toBeNull();
    expect(SiteDeployment::query()->find($kept->id))->not->toBeNull();
});

test('refuses to delete a running deployment', function () {
    [$user, $server, $site] = makeUserSite();
    $running = seedDeploy($site, SiteDeployment::STATUS_RUNNING, now(), 'manual');

    Livewire::actingAs($user)
        ->test(DeploymentsList::class, ['server' => $server, 'site' => $site])
        ->call('deleteDeployment', $running->id);

    expect(SiteDeployment::query()->find($running->id))->not->toBeNull();
});

test('refuses to delete a deployment belonging to another site', function () {
    [$user, $server, $site] = makeUserSite();
    // A sibling site on the same host — same org, so the component still
    // mounts; the guard being tested is the site_id scope, not authorization.
    $otherSite = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $site->user_id,
        'organization_id' => $site->organization_id,
    ]);
    $foreign = seedDeploy($otherSite, SiteDeployment::STATUS_FAILED, now(), 'manual');

    Livewire::actingAs($user)
        ->test(DeploymentsList::class, ['server' => $server, 'site' => $site])
        ->call('deleteDeployment', $foreign->id);

    expect(SiteDeployment::query()->find($foreign->id))->not->toBeNull();
});

test('bulk delete clears failed runs and leaves the rest', function () {
    [$user, $server, $site] = makeUserSite();
    $failedOne = seedDeploy($site, SiteDeployment::STATUS_FAILED, now(), 'manual');
    $failedTwo = seedDeploy($site, SiteDeployment::STATUS_FAILED, now()->subMinutes(5), 'webhook');
    $success = seedDeploy($site, SiteDeployment::STATUS_SUCCESS, now()->subHour(), 'manual');
    $skipped = seedDeploy($site, SiteDeployment::STATUS_SKIPPED, now()->subHours(2), 'manual');

    Livewire::actingAs($user)
        ->test(DeploymentsList::class, ['server' => $server, 'site' => $site])
        ->call('confirmDeleteFailedDeployments')
        ->assertSet('confirmActionModalMethod', 'deleteFailedDeployments')
        ->call('confirmActionModal');

    expect(SiteDeployment::query()->find($failedOne->id))->toBeNull();
    expect(SiteDeployment::query()->find($failedTwo->id))->toBeNull();
    expect(SiteDeployment::query()->find($success->id))->not->toBeNull();
    // A skipped run records a billing/window decision, not a failure — it stays.
    expect(SiteDeployment::query()->find($skipped->id))->not->toBeNull();
});

test('bulk delete does not reach across sites', function () {
    [$user, $server, $site] = makeUserSite();
    $otherSite = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $site->user_id,
        'organization_id' => $site->organization_id,
    ]);
    seedDeploy($site, SiteDeployment::STATUS_FAILED, now(), 'manual');
    $foreign = seedDeploy($otherSite, SiteDeployment::STATUS_FAILED, now(), 'manual');

    Livewire::actingAs($user)
        ->test(DeploymentsList::class, ['server' => $server, 'site' => $site])
        ->call('deleteFailedDeployments');

    expect(SiteDeployment::query()->where('site_id', $site->id)->count())->toBe(0);
    expect(SiteDeployment::query()->find($foreign->id))->not->toBeNull();
});

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
