<?php

namespace Tests\Feature\Livewire\Serverless\JourneyTest;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Modules\Serverless\Jobs\ProvisionServerlessHostJob;
use App\Modules\Serverless\Livewire\Journey as ServerlessJourney;
use App\Modules\Serverless\Support\ServerlessTestingDomains;
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

test('shows invocation and friendly urls for a live function', function () {
    $actionUrl = 'https://faas-nyc1-2ef2e6cc.doserverless.co/api/v1/web/fn-abc/default/laravel-demo';

    [$server, $site] = makeFunction(
        $this->user,
        $this->org,
        serverStatus: Server::STATUS_READY,
        serverMeta: ['digitalocean_functions' => ['api_host' => 'https://faas-nyc1-2ef2e6cc.doserverless.co']],
        siteStatus: Site::STATUS_FUNCTIONS_ACTIVE,
        siteOverrides: ['meta' => [
            'host_kind' => null,
            'serverless' => [
                'action_url' => $actionUrl,
                'proxy_slug' => 'laravel-demo',
                'entrypoint' => 'main',
                'last_revision_id' => '0.0.1',
            ],
        ]],
    );

    $friendlyUrl = 'https://laravel-demo.'.ServerlessTestingDomains::apexFor($site->id);

    Livewire::actingAs($this->user)
        ->test(ServerlessJourney::class, ['server' => $server, 'site' => $site])
        ->assertSee('Invocation URL')
        ->assertSee('Friendly URL')
        ->assertSee($actionUrl)
        ->assertSee($friendlyUrl)
        ->assertDontSee('Friendly URL when DNS is ready')
        ->assertDontSee('DigitalOcean')
        ->assertDontSee('OpenWhisk');
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
            ['key' => 'checkout', 'label' => 'Checked out the repository', 'state' => 'done', 'detail' => '', 'ok' => true],
            ['key' => 'dependencies', 'label' => 'Installing Composer dependencies', 'state' => 'active', 'detail' => 'composer install --no-dev', 'ok' => false],
        ]],
        'log_output' => "[dply] Installing Composer dependencies\ncomposer install --no-dev\n- Installing laravel/framework (v13.0.0)",
    ]);

    Livewire::actingAs($this->user)
        ->test(ServerlessJourney::class, ['server' => $server, 'site' => $site])
        ->assertSee('Checked out the repository')
        ->assertSee('Installing Composer dependencies')
        ->assertSee('composer install --no-dev')
        ->assertSee('Installing laravel/framework')
        ->assertSee('Recent activity')
        ->assertDontSee('Checking out the repo, building the artifact, pushing the action.');
});

test('in-progress deploy without recorded steps still shows the pipeline catalog', function () {
    [$server, $site] = makeFunction($this->user, $this->org, serverStatus: Server::STATUS_READY, serverMeta: ['digitalocean_functions' => ['api_host' => 'https://faas.example']]);
    SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_RUNNING,
        'started_at' => now(),
    ]);

    Livewire::actingAs($this->user)
        ->test(ServerlessJourney::class, ['server' => $server, 'site' => $site])
        ->assertSee('Checking out the repository')
        ->assertSee('Installing dependencies')
        ->assertSee('Pushing the action');
});

test('cancel provision is available while deploying and tears down the unfinished function', function () {
    [$server, $site] = makeFunction($this->user, $this->org, serverStatus: Server::STATUS_READY, serverMeta: ['digitalocean_functions' => ['api_host' => 'https://faas.example']]);
    $deployment = SiteDeployment::query()->create([
        'site_id' => $site->id,
        'project_id' => $site->project_id,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
        'status' => SiteDeployment::STATUS_RUNNING,
        'started_at' => now(),
    ]);
    $siteId = $site->id;
    $serverId = $server->id;
    $deploymentId = $deployment->id;

    Livewire::actingAs($this->user)
        ->test(ServerlessJourney::class, ['server' => $server, 'site' => $site])
        ->assertSee('Cancel provision')
        ->assertDontSee('Cancel deploy')
        ->call('openCancelModal')
        ->assertSet('confirmingCancel', true)
        ->assertSee('Cancel this provision?')
        ->assertSee('will not stay looking live')
        ->assertSee('Cancel and remove')
        ->call('cancelDeploy')
        ->assertRedirect(route('serverless.index'));

    expect(Site::query()->find($siteId))->toBeNull();
    expect(Server::query()->find($serverId))->toBeNull();
    expect(SiteDeployment::query()->find($deploymentId))->toBeNull();
});

test('cancel provision is available on the stuck deploying spinner with no deployment row', function () {
    [$server, $site] = makeFunction(
        $this->user,
        $this->org,
        serverStatus: Server::STATUS_READY,
        serverMeta: ['digitalocean_functions' => ['api_host' => 'https://faas.example']],
        siteStatus: Site::STATUS_FUNCTIONS_CONFIGURED,
    );
    $siteId = $site->id;
    $serverId = $server->id;

    expect(SiteDeployment::query()->where('site_id', $site->id)->exists())->toBeFalse();

    Livewire::actingAs($this->user)
        ->test(ServerlessJourney::class, ['server' => $server, 'site' => $site])
        ->assertSee('Building & deploying')
        ->assertSee('Cancel provision')
        ->assertDontSee('Function is live')
        ->call('openCancelModal')
        ->assertSet('confirmingCancel', true)
        ->assertSee('Cancel this provision?')
        ->call('cancelDeploy')
        ->assertRedirect(route('serverless.index'));

    expect(Site::query()->find($siteId))->toBeNull();
    expect(Server::query()->find($serverId))->toBeNull();
});

test('cancel provision is available while the namespace is still provisioning', function () {
    [$server, $site] = makeFunction($this->user, $this->org);

    Livewire::actingAs($this->user)
        ->test(ServerlessJourney::class, ['server' => $server, 'site' => $site])
        ->assertSee('Provisioning namespace')
        ->assertSee('Cancel provision')
        ->call('openCancelModal')
        ->assertSet('confirmingCancel', true)
        ->assertSee('removes the function and its namespace')
        ->call('cancelDeploy')
        ->assertRedirect(route('serverless.index'));

    expect(Site::query()->find($site->id))->toBeNull();
    expect(Server::query()->find($server->id))->toBeNull();
});

test('cancel is not shown when the function is already live', function () {
    [$server, $site] = makeFunction(
        $this->user,
        $this->org,
        serverStatus: Server::STATUS_READY,
        serverMeta: ['digitalocean_functions' => ['api_host' => 'https://faas.example']],
        siteStatus: Site::STATUS_FUNCTIONS_ACTIVE,
        siteOverrides: ['meta' => [
            'host_kind' => null,
            'serverless' => ['action_url' => 'https://faas.example/web/fn'],
        ]],
    );
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
        ->assertDontSee('Cancel provision')
        ->assertDontSee('Cancel deploy')
        ->assertDontSee('Cancel this provision?');
});

test('cancel in-flight redeploy aborts without deleting the live function', function () {
    [$server, $site] = makeFunction(
        $this->user,
        $this->org,
        serverStatus: Server::STATUS_READY,
        serverMeta: ['digitalocean_functions' => ['api_host' => 'https://faas.example']],
        siteStatus: Site::STATUS_FUNCTIONS_ACTIVE,
    );
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
        ->assertDontSee('Cancel provision')
        ->call('openCancelModal')
        ->assertSet('confirmingCancel', true)
        ->assertSee('Cancel this deploy?')
        ->assertSee('The live function stays up')
        ->call('cancelDeploy')
        ->assertHasNoErrors()
        ->assertSet('confirmingCancel', false);

    expect(Site::query()->find($site->id))->not->toBeNull()
        ->and($site->fresh()->status)->toBe(Site::STATUS_FUNCTIONS_ACTIVE)
        ->and($deployment->fresh()->status)->toBe(SiteDeployment::STATUS_FAILED)
        ->and($deployment->fresh()->log_output)->toContain('Cancelled by operator.');
});

test('namespace provision failure shows the stored reason not the canned sentence', function () {
    [$server, $site] = makeFunction(
        $this->user,
        $this->org,
        serverStatus: Server::STATUS_ERROR,
        serverMeta: [
            'provision_error' => [
                'stage' => 'namespace',
                'message' => 'DigitalOcean API failed to create functions namespace: Unable to authenticate you',
            ],
        ],
    );

    Livewire::actingAs($this->user)
        ->test(ServerlessJourney::class, ['server' => $server, 'site' => $site])
        ->assertSee('Provisioning namespace')
        ->assertSee('Unable to authenticate you')
        ->assertSee('Could not create the functions namespace')
        ->assertDontSee('DigitalOcean Functions')
        ->assertDontSee('DigitalOcean API failed')
        ->assertDontSee('Review the log, fix the issue, then retry.');
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

test('retry provision remaps a rejected credential onto a healthy sibling', function () {
    Bus::fake();
    $rejected = ProviderCredential::query()->create([
        'organization_id' => $this->org->id,
        'user_id' => $this->user->id,
        'provider' => 'digitalocean',
        'name' => 'dsds',
        'credentials' => ['token' => 'dop_v1_stale'],
        'validation_error' => 'DigitalOcean API failed to validate token: Unable to authenticate you',
        'created_at' => now()->subMinute(),
    ]);
    $healthy = ProviderCredential::query()->create([
        'organization_id' => $this->org->id,
        'user_id' => $this->user->id,
        'provider' => 'digitalocean',
        'name' => 'aug_19',
        'credentials' => ['token' => 'dop_v1_ok'],
        'created_at' => now(),
    ]);
    [$server, $site] = makeFunction($this->user, $this->org, serverStatus: Server::STATUS_ERROR);
    $server->update(['provider_credential_id' => $rejected->id]);
    $site->update(['serverless_provider_credential_id' => $rejected->id]);

    Livewire::actingAs($this->user)
        ->test(ServerlessJourney::class, ['server' => $server->fresh(), 'site' => $site->fresh()])
        ->assertSee('dsds')
        ->call('retryProvision')
        ->assertSee('aug_19');

    Bus::assertDispatched(ProvisionServerlessHostJob::class);
    $server->refresh();
    $site->refresh();
    expect($server->status)->toBe(Server::STATUS_PENDING);
    expect($server->provider_credential_id)->toBe($healthy->id)
        ->and($server->provider_credential_id)->not->toBe($rejected->id);
    expect($site->serverless_provider_credential_id)->toBe($healthy->id);
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
