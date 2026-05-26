<?php

declare(strict_types=1);

namespace Tests\Feature\CloudResizeTest;

use App\Enums\SiteType;
use App\Jobs\RedeployCloudSiteJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Cloud\DigitalOceanAppPlatformBackend;
use App\Services\DigitalOceanAppPlatformService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('do backend provision maps size tier to slug', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps' => Http::response([
            'app' => ['id' => 'app-1', 'default_ingress' => null],
        ], 201),
    ]);

    $site = makeContainerSite([
        'meta' => ['container' => ['size_tier' => 'large']],
    ]);

    (new DigitalOceanAppPlatformBackend)->provision($site, credential());

    Http::assertSent(function (Request $request) {
        return ($request->data()['spec']['services'][0]['instance_size_slug'] ?? null) === 'basic-s';
    });
});
test('do service create app uses passed size slug', function () {
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
        instanceSizeSlug: 'professional-xs',
    );

    Http::assertSent(function (Request $request) {
        return ($request->data()['spec']['services'][0]['instance_size_slug'] ?? null) === 'professional-xs';
    });
});
test('cli resize persists tier and queues redeploy', function () {
    Queue::fake();
    config(['server_provision_fake.env_flag' => true]);
    $site = makeContainerSite();

    $exit = Artisan::call('dply:cloud:resize', [
        'site' => $site->name,
        '--size' => 'medium',
    ]);

    expect($exit)->toBe(0);
    expect($site->fresh()->meta['container']['size_tier'])->toBe('medium');
    Queue::assertPushed(RedeployCloudSiteJob::class);
});
test('cli no redeploy skips queue', function () {
    Queue::fake();
    config(['server_provision_fake.env_flag' => true]);
    $site = makeContainerSite();

    Artisan::call('dply:cloud:resize', [
        'site' => $site->name,
        '--size' => 'xlarge',
        '--no-redeploy' => true,
    ]);

    expect($site->fresh()->meta['container']['size_tier'])->toBe('xlarge');
    Queue::assertNotPushed(RedeployCloudSiteJob::class);
});
test('cli rejects unknown tier', function () {
    $site = makeContainerSite();

    $exit = Artisan::call('dply:cloud:resize', [
        'site' => $site->name,
        '--size' => 'jumbo',
    ]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Valid:', Artisan::output());
});
test('cli rejects missing size', function () {
    $site = makeContainerSite();

    $exit = Artisan::call('dply:cloud:resize', ['site' => $site->name]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('--size=', Artisan::output());
});
test('cli rejects non cloud site', function () {
    $user = User::factory()->create();
    $server = Server::factory()->ready()->create(['user_id' => $user->id]);
    $vmSite = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'PHP Site',
        'type' => SiteType::Php,
    ]);

    $exit = Artisan::call('dply:cloud:resize', [
        'site' => $vmSite->name,
        '--size' => 'small',
    ]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('not a cloud container site', Artisan::output());
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
