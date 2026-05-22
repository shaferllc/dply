<?php

declare(strict_types=1);

namespace Tests\Feature\CloudLogsCommandTest;
use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('returns signed url for do site', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps/do-app-1/deployments?per_page=1' => Http::response([
            'deployments' => [['id' => 'dep-9']],
        ], 200),
        'api.digitalocean.com/v2/apps/do-app-1/deployments/dep-9/logs?type=DEPLOY' => Http::response([
            'historic_urls' => ['https://logs.example/signed'],
        ], 200),
    ]);
    $site = makeContainerSite(['container_backend_id' => 'do-app-1']);
    ProviderCredential::query()->create([
        'user_id' => $site->user_id,
        'organization_id' => $site->organization_id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);

    $exit = Artisan::call('dply:cloud:logs', ['site' => $site->name]);

    expect($exit)->toBe(0);
    $this->assertStringContainsString('https://logs.example/signed', Artisan::output());
});
test('returns inline content in fake cloud mode', function () {
    config(['server_provision_fake.env_flag' => true]);

    // Site without container_backend_id triggers the
    // "not provisioned yet" branch on the real DO backend; in
    // fake-cloud mode the site goes through FakeCloudBackend
    // which doesn't care about backend_id and returns inline
    // content. Make sure the test reaches that branch.
    $site = makeContainerSite(['container_backend_id' => null]);

    $exit = Artisan::call('dply:cloud:logs', ['site' => $site->name]);

    expect($exit)->toBe(0);
    $output = Artisan::output();
    $this->assertStringContainsString('fake-edge backend', $output);
    $this->assertStringContainsString('healthcheck OK', $output);
});
test('aws returns cloudwatch message', function () {
    $site = makeContainerSite([
        'container_backend' => 'aws_app_runner',
        'container_backend_id' => 'arn:aws:apprunner:us-east-1:1234:service/edge-app/abc',
    ]);
    ProviderCredential::query()->create([
        'user_id' => $site->user_id,
        'organization_id' => $site->organization_id,
        'provider' => 'aws_app_runner',
        'name' => 'AWS',
        'credentials' => [
            'access_key_id' => 'k',
            'secret_access_key' => 's',
            'region' => 'us-east-1',
        ],
    ]);

    $exit = Artisan::call('dply:cloud:logs', ['site' => $site->name]);

    expect($exit)->toBe(0);
    $this->assertStringContainsString('CloudWatch', Artisan::output());
});
test('rejects non cloud site', function () {
    $user = User::factory()->create();
    $server = Server::factory()->ready()->create(['user_id' => $user->id]);
    $vmSite = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'PHP Site',
        'type' => SiteType::Php,
    ]);

    $exit = Artisan::call('dply:cloud:logs', ['site' => $vmSite->name]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('not a cloud container site', Artisan::output());
});
test('missing site', function () {
    $exit = Artisan::call('dply:cloud:logs', ['site' => 'nope']);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('Site not found', Artisan::output());
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
