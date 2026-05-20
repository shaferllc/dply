<?php

namespace Tests\Feature\Livewire\Serverless;

use App\Jobs\ProvisionServerlessHostJob;
use App\Jobs\RunSiteDeploymentJob;
use App\Livewire\Serverless\Journey as ServerlessJourney;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class JourneyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->org = Organization::factory()->create();
        $this->org->users()->attach($this->user->id, ['role' => 'owner']);
    }

    /**
     * @param  array<string, mixed>  $serverMeta
     * @param  array<string, mixed>  $siteOverrides
     * @return array{0: Server, 1: Site}
     */
    private function makeFunction(string $serverStatus = Server::STATUS_PENDING, array $serverMeta = [], string $siteStatus = Site::STATUS_FUNCTIONS_CONFIGURED, array $siteOverrides = []): array
    {
        $server = Server::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->org->id,
            'status' => $serverStatus,
            'meta' => array_merge(['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS], $serverMeta),
        ]);

        $site = Site::factory()->create(array_merge([
            'server_id' => $server->id,
            'user_id' => $this->user->id,
            'organization_id' => $this->org->id,
            'status' => $siteStatus,
        ], $siteOverrides));

        return [$server, $site];
    }

    public function test_shows_provisioning_stage_for_a_fresh_function(): void
    {
        [$server, $site] = $this->makeFunction();

        Livewire::actingAs($this->user)
            ->test(ServerlessJourney::class, ['server' => $server, 'site' => $site])
            ->assertSee('Provisioning namespace')
            ->assertSee('Building & deploying');
    }

    public function test_shows_live_state_with_the_invocation_url(): void
    {
        [$server, $site] = $this->makeFunction(
            serverStatus: Server::STATUS_READY,
            serverMeta: ['digitalocean_functions' => ['api_host' => 'https://faas.example']],
            siteStatus: Site::STATUS_FUNCTIONS_ACTIVE,
            siteOverrides: ['meta' => [
                'host_kind' => null,
                'serverless' => ['action_url' => 'https://faas.example/web/fn'],
            ]],
        );

        Livewire::actingAs($this->user)
            ->test(ServerlessJourney::class, ['server' => $server, 'site' => $site])
            ->assertSee('live')
            ->assertSee('https://faas.example/web/fn');
    }

    public function test_it_shows_live_deploy_substeps(): void
    {
        [$server, $site] = $this->makeFunction(
            serverStatus: Server::STATUS_READY,
            serverMeta: ['digitalocean_functions' => ['api_host' => 'https://faas.example']],
        );
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
    }

    public function test_cancel_deploy_requests_cancellation_of_the_running_deploy(): void
    {
        [$server, $site] = $this->makeFunction(
            serverStatus: Server::STATUS_READY,
            serverMeta: ['digitalocean_functions' => ['api_host' => 'https://faas.example']],
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
            ->call('cancelDeploy');

        // The deploy pipeline's next checkpoint should now abort.
        $this->expectException(\App\Exceptions\ServerlessDeployCancelledException::class);
        app(\App\Services\Deploy\ServerlessDeployProgress::class)->checkpoint($site->fresh());
    }

    public function test_retry_provision_redispatches_the_host_job_when_errored(): void
    {
        Bus::fake();
        [$server, $site] = $this->makeFunction(serverStatus: Server::STATUS_ERROR);

        Livewire::actingAs($this->user)
            ->test(ServerlessJourney::class, ['server' => $server, 'site' => $site])
            ->call('retryProvision');

        Bus::assertDispatched(ProvisionServerlessHostJob::class);
        $this->assertSame(Server::STATUS_PENDING, $server->fresh()->status);
    }

    public function test_retry_deploy_dispatches_a_deployment(): void
    {
        Bus::fake();
        [$server, $site] = $this->makeFunction(
            serverStatus: Server::STATUS_READY,
            serverMeta: ['digitalocean_functions' => ['api_host' => 'https://faas.example']],
        );
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
    }

    public function test_rejects_a_site_that_is_not_on_the_given_host(): void
    {
        [$server] = $this->makeFunction();
        [, $otherSite] = $this->makeFunction();

        Livewire::actingAs($this->user)
            ->test(ServerlessJourney::class, ['server' => $server, 'site' => $otherSite])
            ->assertStatus(404);
    }
}
