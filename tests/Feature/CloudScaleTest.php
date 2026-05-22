<?php

declare(strict_types=1);

namespace Tests\Feature\CloudScaleTest;

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

test('do create app sends instance count', function () {
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
        instanceCount: 3,
    );

    Http::assertSent(function (Request $request) {
        return ($request->data()['spec']['services'][0]['instance_count'] ?? null) === 3;
    });
});
test('do backend provision reads instance count from meta', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps' => Http::response([
            'app' => ['id' => 'app-1', 'default_ingress' => null],
        ], 201),
    ]);

    $site = makeContainerSite([
        'meta' => ['container' => ['instance_count' => 5]],
    ]);

    (new DigitalOceanAppPlatformBackend)->provision($site, credential());

    Http::assertSent(function (Request $request) {
        return ($request->data()['spec']['services'][0]['instance_count'] ?? null) === 5;
    });
});
test('cli persists instance count and queues redeploy', function () {
    Queue::fake();
    config(['server_provision_fake.env_flag' => true]);
    $site = makeContainerSite();

    $exit = Artisan::call('dply:cloud:scale', [
        'site' => $site->name,
        '--instances' => '4',
    ]);

    expect($exit)->toBe(0);
    expect($site->fresh()->meta['container']['instance_count'])->toBe(4);
    Queue::assertPushed(RedeployCloudSiteJob::class);
});
test('cli no redeploy skips queue', function () {
    Queue::fake();
    config(['server_provision_fake.env_flag' => true]);
    $site = makeContainerSite();

    $exit = Artisan::call('dply:cloud:scale', [
        'site' => $site->name,
        '--instances' => '2',
        '--no-redeploy' => true,
    ]);

    expect($exit)->toBe(0);
    expect($site->fresh()->meta['container']['instance_count'])->toBe(2);
    Queue::assertNotPushed(RedeployCloudSiteJob::class);
});
test('cli rejects missing instances', function () {
    $site = makeContainerSite();

    $exit = Artisan::call('dply:cloud:scale', ['site' => $site->name]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('--instances=', Artisan::output());
});
test('cli rejects out of range instances', function () {
    $site = makeContainerSite();

    $exit = Artisan::call('dply:cloud:scale', [
        'site' => $site->name,
        '--instances' => '0',
    ]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('between 1 and 50', Artisan::output());
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

    $exit = Artisan::call('dply:cloud:scale', [
        'site' => $vmSite->name,
        '--instances' => '3',
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
        'provider' => 'digitalocean_app_platform',
        'name' => 'Test',
        'credentials' => ['api_token' => 'dop_v1_test'],
    ]);
}
