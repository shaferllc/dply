<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\RedeployEdgeSiteJob;
use App\Livewire\Sites\Settings as SitesSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Coverage for the source-mode env var editor on the container
 * dashboard. The editor lives on Sites/Show via the
 * ManagesContainerSite trait — saveContainerEnvAndRedeploy()
 * persists the new env_file_content, asks the backend to push
 * the new env vars into its spec (no-op on FakeEdgeBackend),
 * then dispatches RedeployEdgeSiteJob.
 *
 * Tests run in fake-cloud mode so backend->updateEnvVars() lands
 * on FakeEdgeBackend and doesn't try to call DO/AWS.
 */
class EdgeEnvEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_persists_env_and_dispatches_redeploy(): void
    {
        Queue::fake();
        config(['server_provision_fake.env_flag' => true]);
        [$user, $server, $site] = $this->makeSourceModeSite();

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->set('container_env_file_input', "APP_ENV=production\nLOG_LEVEL=debug\n")
            ->call('saveContainerEnvAndRedeploy')
            ->assertHasNoErrors();

        $fresh = $site->fresh();
        $this->assertStringContainsString('APP_ENV=production', (string) $fresh->env_file_content);
        $this->assertStringContainsString('LOG_LEVEL=debug', (string) $fresh->env_file_content);

        Queue::assertPushed(RedeployEdgeSiteJob::class, fn (RedeployEdgeSiteJob $j) => $j->siteId === $site->id);
    }

    public function test_dashboard_renders_env_editor_block(): void
    {
        config(['server_provision_fake.env_flag' => true]);
        [$user, $server, $site] = $this->makeSourceModeSite();

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertSee('Environment variables')
            ->assertSee('Save &amp; redeploy', false);
    }

    public function test_save_is_idempotent_when_env_unchanged(): void
    {
        Queue::fake();
        config(['server_provision_fake.env_flag' => true]);
        [$user, $server, $site] = $this->makeSourceModeSite();

        Livewire::actingAs($user)
            ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('saveContainerEnvAndRedeploy')
            ->assertHasNoErrors();

        Queue::assertPushed(RedeployEdgeSiteJob::class);
    }

    /**
     * @return array{0: User, 1: Server, 2: Site}
     */
    private function makeSourceModeSite(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        // Real DO credential so EdgeRouter doesn't swap in the fake
        // backend (we want to exercise the regular dispatch path —
        // the backend's updateEnvVars will throw without an HTTP fake,
        // so this test stays in fake-cloud mode by skipping the cred).
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'name' => 'API service',
            'slug' => 'api-service',
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => null,
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'container_backend_id' => 'fake-app-1',
            'env_file_content' => "APP_ENV=staging\n",
            'status' => Site::STATUS_CONTAINER_ACTIVE,
            'meta' => [
                'container' => [
                    'source' => [
                        'repo' => 'acme/api',
                        'branch' => 'main',
                        'deploy_on_push' => true,
                    ],
                ],
            ],
        ]);

        return [$user, $server, $site];
    }
}
