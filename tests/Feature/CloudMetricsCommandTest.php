<?php

declare(strict_types=1);

namespace Tests\Feature\CloudMetricsCommandTest;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('metrics command prints series for do site', function () {
    Http::fake([
        'api.digitalocean.com/v2/monitoring/metrics/apps/*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix', 'result' => [[
                'metric' => ['app_component' => 'web'],
                'values' => [[1700000000, '22.0'], [1700000060, '44.0']],
            ]]],
        ], 200),
    ]);
    $site = makeContainerSite(['container_backend_id' => 'do-app-1']);
    doCredential($site);

    $exit = Artisan::call('dply:cloud:metrics', ['site' => $site->name, '--window' => '6h']);

    expect($exit)->toBe(0);
    $output = Artisan::output();
    $this->assertStringContainsString('CPU', $output);
    $this->assertStringContainsString('window: 6h', $output);
});
test('metrics command json output', function () {
    Http::fake([
        'api.digitalocean.com/v2/monitoring/metrics/apps/*' => Http::response([
            'status' => 'success',
            'data' => ['resultType' => 'matrix', 'result' => [[
                'metric' => ['app_component' => 'web'],
                'values' => [[1700000000, '5']],
            ]]],
        ], 200),
    ]);
    $site = makeContainerSite(['container_backend_id' => 'do-app-1']);
    doCredential($site);

    $exit = Artisan::call('dply:cloud:metrics', ['site' => $site->name, '--json' => true]);

    expect($exit)->toBe(0);
    $decoded = json_decode(Artisan::output(), true);
    expect($decoded)->toBeArray();
    expect($decoded['metrics']['available'])->toBeTrue();
});
test('metrics command synthetic in fake cloud mode', function () {
    config(['server_provision_fake.env_flag' => true]);
    $site = makeContainerSite(['container_backend_id' => null]);

    $exit = Artisan::call('dply:cloud:metrics', ['site' => $site->name]);

    expect($exit)->toBe(0);
    $this->assertStringContainsString('CPU', Artisan::output());
});
test('metrics command shows cloudwatch fallback for aws', function () {
    $site = makeContainerSite([
        'container_backend' => 'aws_app_runner',
        'container_backend_id' => 'arn:aws:apprunner:us-east-1:1:service/edge/x',
    ]);
    ProviderCredential::query()->create([
        'user_id' => $site->user_id,
        'organization_id' => $site->organization_id,
        'provider' => 'aws_app_runner',
        'name' => 'AWS',
        'credentials' => ['access_key_id' => 'k', 'secret_access_key' => 's', 'region' => 'us-east-1'],
    ]);

    $exit = Artisan::call('dply:cloud:metrics', ['site' => $site->name]);

    expect($exit)->toBe(0);
    $this->assertStringContainsString('CloudWatch', Artisan::output());
});
test('metrics command rejects non cloud site', function () {
    $user = User::factory()->create();
    $server = Server::factory()->ready()->create(['user_id' => $user->id]);
    $vmSite = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'PHP Site',
        'type' => SiteType::Php,
    ]);

    $exit = Artisan::call('dply:cloud:metrics', ['site' => $vmSite->name]);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('not a cloud container site', Artisan::output());
});
test('logs run flag prints runtime logs for do site', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps/do-app-1/components/web/logs*' => Http::response([
            'historic_urls' => ['https://logs.example/run.log'],
        ], 200),
        'logs.example/run.log' => Http::response("runtime line A\nruntime line B", 200),
    ]);
    $site = makeContainerSite(['container_backend_id' => 'do-app-1']);
    doCredential($site);

    $exit = Artisan::call('dply:cloud:logs', ['site' => $site->name, '--run' => true]);

    expect($exit)->toBe(0);
    $output = Artisan::output();
    $this->assertStringContainsString('runtime line A', $output);
    $this->assertStringContainsString('runtime line B', $output);
});
test('logs run flag synthetic in fake cloud mode', function () {
    config(['server_provision_fake.env_flag' => true]);
    $site = makeContainerSite(['container_backend_id' => null]);

    $exit = Artisan::call('dply:cloud:logs', ['site' => $site->name, '--run' => true]);

    expect($exit)->toBe(0);
    $this->assertStringContainsString('fake-edge', Artisan::output());
});
test('logs run flag shows cloudwatch link for aws', function () {
    $site = makeContainerSite([
        'container_backend' => 'aws_app_runner',
        'container_backend_id' => 'arn:aws:apprunner:us-east-1:1:service/edge/x',
    ]);
    ProviderCredential::query()->create([
        'user_id' => $site->user_id,
        'organization_id' => $site->organization_id,
        'provider' => 'aws_app_runner',
        'name' => 'AWS',
        'credentials' => ['access_key_id' => 'k', 'secret_access_key' => 's', 'region' => 'us-east-1'],
    ]);

    $exit = Artisan::call('dply:cloud:logs', ['site' => $site->name, '--run' => true]);

    expect($exit)->toBe(0);
    $this->assertStringContainsString('cloudwatch', Artisan::output());
});
function doCredential(Site $site): void
{
    ProviderCredential::query()->create([
        'user_id' => $site->user_id,
        'organization_id' => $site->organization_id,
        'provider' => 'digitalocean_app_platform',
        'name' => 'DO',
        'credentials' => ['api_token' => 't'],
    ]);
}
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
        'container_backend_id' => 'do-app-1',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
    ], $overrides));
}
