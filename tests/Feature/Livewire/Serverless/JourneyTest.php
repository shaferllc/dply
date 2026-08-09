<?php

namespace Tests\Feature\Livewire\Serverless\JourneyTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Modules\Deploy\Services\ServerlessDeployProgress;
use App\Modules\Serverless\Exceptions\ServerlessDeployCancelledException;
use App\Modules\Serverless\Jobs\ProvisionServerlessHostJob;
use App\Modules\Serverless\Livewire\Journey as ServerlessJourney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);
usesFeatures('surface.serverless');

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->org = Organization::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'owner']);
    session(['current_organization_id' => $this->org->id]);
});

/**
 * @param  array<string, mixed>  $serverMeta
 * @param  array<string, mixed>  $siteOverrides
 * @return array{0: Server, 1: Site}
 */
function makeFunction(User $user, Organization $org, string $serverStatus = Server::STATUS_PENDING, array $serverMeta = [], string $siteStatus = Site::STATUS_FUNCTIONS_CONFIGURED, array $siteOverrides = []): array
{
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => $serverStatus,
        'meta' => array_merge(['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS], $serverMeta),
    ]);

    $site = Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => $siteStatus,
    ], $siteOverrides));

    return [$server, $site];
}

test('shows provisioning stage for a fresh function', function () {
    [$server, $site] = makeFunction($this->user, $this->org);

    Livewire::actingAs($this->user)
        ->test(ServerlessJourney::class, ['server' => $server, 'site' => $site])
        ->assertSee('Provisioning namespace')
        ->assertSee('Building & deploying');
});

test('shows live state with the invocation url', function () {
    [$server, $site] = makeFunction($this->user, $this->org, serverStatus: Server::STATUS_READY, serverMeta: ['digitalocean_functions' => ['api_host' => 'https://faas.example']], siteStatus: Site::STATUS_FUNCTIONS_ACTIVE, siteOverrides: ['meta' => [
        'host_kind' => null,
        'serverless' => ['action_url' => 'https://faas.example/web/fn'],
    ]]);

    Livewire::actingAs($this->user)
        ->test(ServerlessJourney::class, ['server' => $server, 'site' => $site])
        ->assertSee('live')
        ->assertSee('https://faas.example/web/fn');
});

test('it shows live deploy substeps', function () {
    [$server, $site] = makeFunction($this->user, $this->org, serverStatus: Server::STATUS_READY, serverMeta: ['digitalocean_functions' => ['api_host' => 'https://faas.example']]);
    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_RUNNING,
        'started_at' => now(),
        'phase_results' => ['serverless' => [
            ['key' => 'checkout', 'label' => 'Cloned repository', 'state' => 'done', 'detail' => '', 'ok' => true],
            ['key' => 'dependencies', 'label' => 'Installing dependencies', 'state' => 'active', 'detail' => '', 'ok' => false],
        ]],
    ]);

    Livewire::actingAs($this->user)
        ->test(ServerlessJourney::class, ['server' => $server, 'site' => $site])
        ->assertSee('Cloned repository')
        ->assertSee('Installing dependencies');
});

test('cancel deploy requests cancellation of the running deploy', function () {
    [$server, $site] = makeFunction($this->user, $this->org, serverStatus: Server::STATUS_READY, serverMeta: ['digitalocean_functions' => ['api_host' => 'https://faas.example']]);
    $deployment = SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_RUNNING,
        'started_at' => now(),
    ]);

    Livewire::actingAs($this->user)
        ->test(ServerlessJourney::class, ['server' => $server, 'site' => $site])
        ->assertSee('Cancel deploy')
        ->call('cancelDeploy');

    // The deploy pipeline's next checkpoint should now abort.
    $this->expectException(ServerlessDeployCancelledException::class);
    app(ServerlessDeployProgress::class)->checkpoint($site->fresh());
});

test('retry provision redispatches the host job when errored', function () {
    Bus::fake();
    [$server, $site] = makeFunction($this->user, $this->org, serverStatus: Server::STATUS_ERROR);

    Livewire::actingAs($this->user)
        ->test(ServerlessJourney::class, ['server' => $server, 'site' => $site])
        ->call('retryProvision');

    Bus::assertDispatched(ProvisionServerlessHostJob::class);
    expect($server->fresh()->status)->toBe(Server::STATUS_PENDING);
});

test('retry deploy dispatches a deployment', function () {
    Bus::fake();
    [$server, $site] = makeFunction($this->user, $this->org, serverStatus: Server::STATUS_READY, serverMeta: ['digitalocean_functions' => ['api_host' => 'https://faas.example']]);
    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_FAILED,
        'log_output' => 'boom',
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    Livewire::actingAs($this->user)
        ->test(ServerlessJourney::class, ['server' => $server, 'site' => $site])
        ->assertSee('Retry deploy')
        ->call('retryDeploy');

    Bus::assertDispatched(RunSiteDeploymentJob::class);
});

test('failed first deploy shows deploy failed not stopped', function () {
    [$server, $site] = makeFunction(
        $this->user,
        $this->org,
        serverStatus: Server::STATUS_READY,
        serverMeta: ['digitalocean_functions' => ['api_host' => 'https://faas.example']],
        siteStatus: Site::STATUS_FUNCTIONS_FAILED,
    );
    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_FAILED,
        'log_output' => 'Repository preflight failed',
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    Livewire::actingAs($this->user)
        ->test(ServerlessJourney::class, ['server' => $server, 'site' => $site])
        ->assertSee('Deploy failed')
        ->assertSee('Repository preflight failed')
        ->assertDontSee('Deploy stopped');
});

test('redeploy dispatches a deployment for a live function', function () {
    Bus::fake();
    [$server, $site] = makeFunction($this->user, $this->org, serverStatus: Server::STATUS_READY, serverMeta: ['digitalocean_functions' => ['api_host' => 'https://faas.example']], siteStatus: Site::STATUS_FUNCTIONS_ACTIVE);
    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_SUCCESS,
        'started_at' => now()->subMinute(),
        'finished_at' => now()->subMinute(),
    ]);

    Livewire::actingAs($this->user)
        ->test(ServerlessJourney::class, ['server' => $server, 'site' => $site])
        ->assertSee('Function is live')
        ->assertSee('Redeploy')
        ->call('redeploy')
        // The bridge keeps the page polling until the new deploy appears.
        ->assertSet('sinceDeploymentId', fn ($v): bool => $v !== null);

    Bus::assertDispatched(RunSiteDeploymentJob::class);
});

test('rejects a site that is not on the given host', function () {
    [$server] = makeFunction($this->user, $this->org);
    [, $otherSite] = makeFunction($this->user, $this->org);

    Livewire::actingAs($this->user)
        ->test(ServerlessJourney::class, ['server' => $server, 'site' => $otherSite])
        ->assertStatus(404);
});

test('deletes the failed run the journey is showing', function () {
    [$server, $site] = makeFunction(
        $this->user,
        $this->org,
        serverStatus: Server::STATUS_READY,
        serverMeta: ['digitalocean_functions' => ['api_host' => 'https://faas.example']],
        siteStatus: Site::STATUS_FUNCTIONS_FAILED,
    );
    $failed = SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_FAILED,
        'log_output' => 'HTTP 401 The supplied authentication is invalid',
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    Livewire::actingAs($this->user)
        ->test(ServerlessJourney::class, ['server' => $server, 'site' => $site])
        ->assertSee('Delete failed run')
        ->call('openDeleteDeploymentModal')
        ->assertSet('confirmingDeleteDeployment', true)
        ->call('deleteFailedDeployment')
        ->assertSet('confirmingDeleteDeployment', false);

    expect(SiteDeployment::query()->find($failed->id))->toBeNull();
});

test('will not delete a running deploy from the journey', function () {
    [$server, $site] = makeFunction($this->user, $this->org, serverStatus: Server::STATUS_READY, serverMeta: ['digitalocean_functions' => ['api_host' => 'https://faas.example']]);
    $running = SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_RUNNING,
        'started_at' => now(),
    ]);

    Livewire::actingAs($this->user)
        ->test(ServerlessJourney::class, ['server' => $server, 'site' => $site])
        ->assertDontSee('Delete failed run')
        ->call('deleteFailedDeployment');

    expect(SiteDeployment::query()->find($running->id))->not->toBeNull();
});
