<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Jobs\RedeployEdgeSiteJob;
use App\Jobs\TeardownEdgeSiteJob;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ContainerSiteDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_container_panel_for_container_site(): void
    {
        [$user, $server, $site] = $this->makeContainerSite();

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertSee('Dply edge')
            ->assertSee('Container deployment')
            ->assertSee('DigitalOcean App Platform')
            ->assertSee('ghcr.io/acme/api:v1');
    }

    public function test_dashboard_shows_live_url_when_set(): void
    {
        [$user, $server, $site] = $this->makeContainerSite();
        $site->update(['meta' => ['container' => ['live_url' => 'https://acme.ondigitalocean.app']]]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertSee('acme.ondigitalocean.app');
    }

    public function test_dashboard_shows_pending_message_when_url_not_yet_known(): void
    {
        [$user, $server, $site] = $this->makeContainerSite();

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertSee('Pending');
    }

    public function test_dashboard_shows_last_error_when_present(): void
    {
        [$user, $server, $site] = $this->makeContainerSite();
        $site->update([
            'status' => Site::STATUS_CONTAINER_FAILED,
            'meta' => ['container' => [
                'last_error' => 'invalid spec: image not found',
                'last_error_at' => '2026-05-03T05:00:00+00:00',
            ]],
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertSee('Failed')
            ->assertSee('invalid spec: image not found');
    }

    public function test_redeploy_button_dispatches_job_with_no_image_change(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeContainerSite();

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('redeployContainer');

        Queue::assertPushed(RedeployEdgeSiteJob::class, function (RedeployEdgeSiteJob $job) use ($site): bool {
            return $job->siteId === $site->id && $job->newImage === null;
        });
    }

    public function test_redeploy_passes_new_image_when_input_changed(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeContainerSite();

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->set('container_image_input', 'ghcr.io/acme/api:v2')
            ->call('redeployContainer');

        Queue::assertPushed(RedeployEdgeSiteJob::class, function (RedeployEdgeSiteJob $job) use ($site): bool {
            return $job->siteId === $site->id && $job->newImage === 'ghcr.io/acme/api:v2';
        });
    }

    public function test_teardown_button_dispatches_job(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeContainerSite();

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->call('tearDownContainer');

        Queue::assertPushed(TeardownEdgeSiteJob::class);
    }

    public function test_panel_does_not_render_for_non_container_site(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'type' => SiteType::Php,
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertDontSee('Container deployment');
    }

    public function test_source_mode_dashboard_shows_repo_branch_not_image_input(): void
    {
        [$user, $server, $site] = $this->makeContainerSite();
        $site->update([
            'container_image' => null,
            'meta' => [
                'container' => [
                    'source' => [
                        'repo' => 'acme/api',
                        'branch' => 'main',
                        'dockerfile_path' => 'docker/Dockerfile',
                        'deploy_on_push' => true,
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertSee('Container deployment')
            ->assertSee('Source')
            ->assertSee('acme/api')
            ->assertSee('docker/Dockerfile')
            ->assertSee('Auto-deploy on push')
            ->assertSee('Redeploy from latest')
            ->assertDontSee('Image reference');
    }

    public function test_image_mode_dashboard_still_shows_image_input(): void
    {
        [$user, $server, $site] = $this->makeContainerSite();

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertSee('Image reference')
            ->assertSee('ghcr.io/acme/api:v1')
            ->assertDontSee('Auto-deploy on push');
    }

    /**
     * @return array{0: User, 1: Server, 2: Site}
     */
    private function makeContainerSite(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'type' => SiteType::Container,
            'runtime' => null,
            'document_root' => null,
            'repository_path' => null,
            'container_image' => 'ghcr.io/acme/api:v1',
            'container_port' => 8080,
            'container_backend' => 'digitalocean_app_platform',
            'container_region' => 'nyc',
            'status' => Site::STATUS_CONTAINER_PROVISIONING,
        ]);

        return [$user, $server, $site];
    }
}
