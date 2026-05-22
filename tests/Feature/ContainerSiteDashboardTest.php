<?php

declare(strict_types=1);

namespace Tests\Feature\ContainerSiteDashboardTest;
use App\Enums\SiteType;
use App\Jobs\RedeployCloudSiteJob;
use App\Jobs\TeardownCloudSiteJob;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('dashboard renders container panel for container site', function () {
    [$user, $server, $site] = makeContainerSite();

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

    $response->assertOk()
        ->assertSee('Dply cloud')
        ->assertSee('Container deployment')
        ->assertSee('DigitalOcean App Platform')
        ->assertSee('ghcr.io/acme/api:v1');
});
test('dashboard shows live url when set', function () {
    [$user, $server, $site] = makeContainerSite();
    $site->update(['meta' => ['container' => ['live_url' => 'https://acme.ondigitalocean.app']]]);

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

    $response->assertSee('acme.ondigitalocean.app');
});
test('dashboard shows pending message when url not yet known', function () {
    [$user, $server, $site] = makeContainerSite();

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

    $response->assertSee('Pending');
});
test('dashboard shows last error when present', function () {
    [$user, $server, $site] = makeContainerSite();
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
});
test('redeploy button dispatches job with no image change', function () {
    Queue::fake();
    [$user, $server, $site] = makeContainerSite();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('redeployContainer');

    Queue::assertPushed(RedeployCloudSiteJob::class, function (RedeployCloudSiteJob $job) use ($site): bool {
        return $job->siteId === $site->id && $job->newImage === null;
    });
});
test('redeploy passes new image when input changed', function () {
    Queue::fake();
    [$user, $server, $site] = makeContainerSite();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->set('container_image_input', 'ghcr.io/acme/api:v2')
        ->call('redeployContainer');

    Queue::assertPushed(RedeployCloudSiteJob::class, function (RedeployCloudSiteJob $job) use ($site): bool {
        return $job->siteId === $site->id && $job->newImage === 'ghcr.io/acme/api:v2';
    });
});
test('teardown button dispatches job', function () {
    Queue::fake();
    [$user, $server, $site] = makeContainerSite();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('tearDownContainer');

    Queue::assertPushed(TeardownCloudSiteJob::class);
});
test('panel does not render for non container site', function () {
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
});
test('source mode dashboard shows repo branch not image input', function () {
    [$user, $server, $site] = makeContainerSite();
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
});
test('image mode dashboard still shows image input', function () {
    [$user, $server, $site] = makeContainerSite();

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

    $response->assertOk()
        ->assertSee('Image reference')
        ->assertSee('ghcr.io/acme/api:v1')
        ->assertDontSee('Auto-deploy on push');
});
/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeContainerSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
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
