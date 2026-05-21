<?php

namespace Tests\Feature\Livewire\Sites;

use App\Jobs\RunSiteDeploymentJob;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class ServerlessDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function functionSite(array $serverlessMeta): array
    {
        $user = User::factory()->create();
        $org = \App\Models\Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $server = Server::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Server::STATUS_READY,
            'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_FUNCTIONS_ACTIVE,
            'git_repository_url' => 'acme/api',
            'meta' => [
                'runtime_profile' => 'digitalocean_functions_web',
                'serverless' => $serverlessMeta,
            ],
        ]);

        return [$user, $server, $site];
    }

    public function test_general_section_shows_the_invocation_url_for_a_deployed_function(): void
    {
        [$user, $server, $site] = $this->functionSite([
            'runtime' => 'nodejs:20',
            'action_url' => 'https://faas-nyc1.doserverless.co/api/v1/web/fn-abc/default/api',
            'last_revision_id' => '7',
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->assertOk()
            ->assertSee('Invocation URL')
            ->assertSee('faas-nyc1.doserverless.co')
            ->assertSee('nodejs:20')
            ->assertSee('Deploy / redeploy');
    }

    public function test_pre_deploy_function_shows_a_pending_url_notice(): void
    {
        [$user, $server, $site] = $this->functionSite(['runtime' => 'nodejs:20']);
        $site->update(['status' => Site::STATUS_FUNCTIONS_CONFIGURED]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site->fresh(), 'section' => 'general'])
            ->assertOk()
            ->assertSee('appears here once the first deploy completes');
    }

    public function test_deploy_redeploy_button_dispatches_a_deployment_and_redirects_to_the_journey(): void
    {
        Bus::fake();
        [$user, $server, $site] = $this->functionSite([
            'runtime' => 'php:8.4',
            'action_url' => 'https://faas-nyc1.doserverless.co/api/v1/web/fn-abc/default/api',
        ]);

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('redeployServerlessFunction')
            ->assertRedirect(route('serverless.journey', ['server' => $server, 'site' => $site]));

        Bus::assertDispatched(RunSiteDeploymentJob::class);
    }
}
