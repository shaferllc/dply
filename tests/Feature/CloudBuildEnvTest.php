<?php

declare(strict_types=1);

namespace Tests\Feature\CloudBuildEnvTest;

use App\Enums\SiteType;
use App\Modules\Cloud\Jobs\RedeployCloudSiteJob;
use App\Livewire\Sites\Settings as SitesSettings;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Cloud\Backends\DigitalOceanAppPlatformBackend;
use App\Modules\Cloud\Services\DigitalOceanAppPlatformService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\WithFeatures;

uses(RefreshDatabase::class);

uses(WithFeatures::class);

test('do service create app includes build time envs', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps' => Http::response([
            'app' => ['id' => 'app-1', 'default_ingress' => null],
        ], 201),
    ]);

    $service = new DigitalOceanAppPlatformService(credential());
    $service->createApp(
        appName: 'svc',
        region: 'nyc',
        image: 'nginx:1',
        port: 80,
        envVars: ['APP_ENV' => 'production'],
        buildEnvVars: ['NPM_TOKEN' => 'ghp_xxx'],
    );

    Http::assertSent(function (Request $request) {
        $envs = $request->data()['spec']['services'][0]['envs'] ?? [];
        $byKey = [];
        foreach ($envs as $entry) {
            $byKey[$entry['key']] = $entry['scope'];
        }

        return ($byKey['APP_ENV'] ?? null) === 'RUN_TIME'
            && ($byKey['NPM_TOKEN'] ?? null) === 'BUILD_TIME';
    });
});
test('do backend provision pushes build envs from meta', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps' => Http::response([
            'app' => ['id' => 'app-1', 'default_ingress' => null],
        ], 201),
    ]);

    $site = makeContainerSite([
        'env_file_content' => "APP_ENV=production\n",
        'meta' => ['container' => ['build_env_file_content' => "NPM_TOKEN=ghp_xxx\n"]],
    ]);

    $cred = credential();
    (new DigitalOceanAppPlatformBackend)->provision($site, $cred);

    Http::assertSent(function (Request $request) {
        $envs = $request->data()['spec']['services'][0]['envs'] ?? [];
        $byKey = [];
        foreach ($envs as $entry) {
            $byKey[$entry['key']] = $entry['scope'];
        }

        return ($byKey['APP_ENV'] ?? null) === 'RUN_TIME'
            && ($byKey['NPM_TOKEN'] ?? null) === 'BUILD_TIME';
    });
});
test('dashboard save persists build env', function () {
    Queue::fake();
    config(['server_provision_fake.env_flag' => true]);
    [$user, $server, $site] = scaffoldSite();

    Livewire::actingAs($user)
        ->test(SitesSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->set('container_env_file_input', "APP_ENV=production\n")
        ->set('container_build_env_file_input', "NPM_TOKEN=ghp_xxx\n")
        ->call('saveContainerEnvAndRedeploy')
        ->assertHasNoErrors();

    $fresh = $site->fresh();
    $this->assertStringContainsString('APP_ENV=production', $fresh->env_file_content);
    $this->assertStringContainsString('NPM_TOKEN=ghp_xxx', $fresh->meta['container']['build_env_file_content']);
    Queue::assertPushed(RedeployCloudSiteJob::class);
});
test('cli build flag targets build env storage', function () {
    Queue::fake();
    config(['server_provision_fake.env_flag' => true]);
    [, , $site] = scaffoldSite();

    $exit = Artisan::call('dply:cloud:env', [
        'site' => $site->name,
        '--set' => ['NPM_TOKEN=ghp_xxx'],
        '--build' => true,
        '--no-redeploy' => true,
    ]);

    expect($exit)->toBe(0);
    $fresh = $site->fresh();
    $this->assertStringContainsString('NPM_TOKEN=ghp_xxx', $fresh->meta['container']['build_env_file_content']);

    // Runtime env should not have been touched.
    expect((string) $fresh->env_file_content)->toBe('');
});
test('cli default targets runtime env', function () {
    Queue::fake();
    config(['server_provision_fake.env_flag' => true]);
    [, , $site] = scaffoldSite();

    Artisan::call('dply:cloud:env', [
        'site' => $site->name,
        '--set' => ['APP_ENV=staging'],
        '--no-redeploy' => true,
    ]);

    $fresh = $site->fresh();
    $this->assertStringContainsString('APP_ENV=staging', $fresh->env_file_content);
    expect($fresh->meta['container']['build_env_file_content'] ?? '')->toBeEmpty();
});
test('doctor reports env state', function () {
    config(['server_provision_fake.env_flag' => true]);
    [, , $site] = scaffoldSite();
    $site->update([
        'env_file_content' => "APP_ENV=production\n",
        'meta' => ['container' => ['build_env_file_content' => "NPM_TOKEN=ghp_xxx\n"]],
    ]);

    Artisan::call('dply:cloud:doctor', ['site' => $site->name, '--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($payload['env']['runtime_set'])->toBeTrue();
    expect($payload['env']['build_set'])->toBeTrue();
});
/**
 * @param  array<string, mixed>  $overrides
 */
function makeContainerSite(array $overrides = []): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);

    return Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'edge-app',
        'slug' => 'edge-app',
        'type' => SiteType::Container,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'container_image' => 'nginx:1',
        'container_port' => 80,
        'container_backend' => 'digitalocean_app_platform',
        'container_region' => 'nyc',
        'container_backend_id' => 'fake-app-1',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
    ], $overrides));
}
/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function scaffoldSite(): array
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
        'env_file_content' => '',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
        'meta' => [
            'container' => [
                'source' => ['repo' => 'acme/api', 'branch' => 'main', 'deploy_on_push' => true],
            ],
        ],
    ]);

    return [$user, $server, $site];
}
function credential(): ProviderCredential
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    return ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'Test',
        'credentials' => ['api_token' => 'dop_v1_test'],
    ]);
}
