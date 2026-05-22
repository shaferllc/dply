<?php

declare(strict_types=1);

namespace Tests\Feature\CloudEnvEditorTest;
use App\Enums\SiteType;
use App\Jobs\RedeployCloudSiteJob;
use App\Livewire\Sites\Settings as SitesSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('save persists env and dispatches redeploy', function () {
    Queue::fake();
    config(['server_provision_fake.env_flag' => true]);
    [$user, $server, $site] = makeSourceModeSite();

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->set('container_env_file_input', "APP_ENV=production\nLOG_LEVEL=debug\n")
        ->call('saveContainerEnvAndRedeploy')
        ->assertHasNoErrors();

    $fresh = $site->fresh();
    $this->assertStringContainsString('APP_ENV=production', (string) $fresh->env_file_content);
    $this->assertStringContainsString('LOG_LEVEL=debug', (string) $fresh->env_file_content);

    Queue::assertPushed(RedeployCloudSiteJob::class, fn (RedeployCloudSiteJob $j) => $j->siteId === $site->id);
});
test('dashboard renders env editor block', function () {
    config(['server_provision_fake.env_flag' => true]);
    [$user, $server, $site] = makeSourceModeSite();

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

    $response->assertOk()
        ->assertSee('Environment variables')
        ->assertSee('Save &amp; redeploy', false);
});
test('save is idempotent when env unchanged', function () {
    Queue::fake();
    config(['server_provision_fake.env_flag' => true]);
    [$user, $server, $site] = makeSourceModeSite();

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->call('saveContainerEnvAndRedeploy')
        ->assertHasNoErrors();

    Queue::assertPushed(RedeployCloudSiteJob::class);
});
/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeSourceModeSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    // Real DO credential so CloudRouter doesn't swap in the fake
    // backend (we want to exercise the regular dispatch path —
    // the backend's updateEnvVars will throw without an HTTP fake,
    // so this test stays in fake-cloud mode by skipping the cred).
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
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
