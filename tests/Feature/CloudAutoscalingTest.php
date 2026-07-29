<?php

declare(strict_types=1);

namespace Tests\Feature\CloudAutoscalingTest;

use App\Modules\Cloud\Actions\ConfigureCloudAutoscaling;
use App\Modules\Cloud\Actions\ConfigureCloudHealthCheck;
use App\Enums\SiteType;
use App\Modules\Cloud\Jobs\SyncCloudScalingJob;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\CloudWorker;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Cloud\Services\AwsAppRunnerService;
use App\Modules\Cloud\Backends\AwsAppRunnerBackend;
use App\Modules\Cloud\Backends\CloudRouter;
use App\Modules\Cloud\Backends\CloudScalingConfig;
use App\Modules\Cloud\Backends\DigitalOceanAppPlatformBackend;
use Aws\AppRunner\AppRunnerClient;
use Aws\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Livewire\Livewire;
use Mockery;

uses(RefreshDatabase::class);

test('autoscaling validation rejects min below one', function () {
    $this->expectException(InvalidArgumentException::class);
    CloudScalingConfig::validateAutoscaling(['enabled' => true, 'min_instances' => 0, 'max_instances' => 3, 'cpu_percent' => 70]);
});
test('autoscaling validation rejects max below min', function () {
    $this->expectException(InvalidArgumentException::class);
    CloudScalingConfig::validateAutoscaling(['enabled' => true, 'min_instances' => 5, 'max_instances' => 2, 'cpu_percent' => 70]);
});
test('autoscaling validation rejects cpu out of range', function () {
    $this->expectException(InvalidArgumentException::class);
    CloudScalingConfig::validateAutoscaling(['enabled' => true, 'min_instances' => 1, 'max_instances' => 3, 'cpu_percent' => 150]);
});
test('autoscaling validation accepts valid config', function () {
    $config = CloudScalingConfig::validateAutoscaling(['enabled' => true, 'min_instances' => 2, 'max_instances' => 8, 'cpu_percent' => 65]);
    expect($config['min_instances'])->toBe(2);
    expect($config['max_instances'])->toBe(8);
    expect($config['cpu_percent'])->toBe(65);
    expect($config['enabled'])->toBeTrue();
});
test('health check validation rejects path without slash', function () {
    $this->expectException(InvalidArgumentException::class);
    CloudScalingConfig::validateHealthCheck(['enabled' => true, 'http_path' => 'health']);
});
test('health check validation rejects non positive threshold', function () {
    $this->expectException(InvalidArgumentException::class);
    CloudScalingConfig::validateHealthCheck(['enabled' => true, 'http_path' => '/health', 'period_seconds' => 0]);
});
test('health check validation accepts valid config', function () {
    $config = CloudScalingConfig::validateHealthCheck([
        'enabled' => true,
        'http_path' => '/up',
        'initial_delay_seconds' => 5,
        'period_seconds' => 15,
        'timeout_seconds' => 3,
        'success_threshold' => 2,
        'failure_threshold' => 5,
    ]);
    expect($config['http_path'])->toBe('/up');
    expect($config['period_seconds'])->toBe(15);
    expect($config['failure_threshold'])->toBe(5);
});
test('config defaults when meta absent', function () {
    $site = makeContainerSite();
    $autoscaling = CloudScalingConfig::autoscaling($site);
    $healthCheck = CloudScalingConfig::healthCheck($site);

    expect($autoscaling['enabled'])->toBeFalse();
    expect($healthCheck['enabled'])->toBeFalse();
    expect($healthCheck['http_path'])->toBe(CloudScalingConfig::DEFAULT_HEALTH_PATH);
});
test('do provision emits autoscaling block and omits instance count', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps' => Http::response([
            'app' => ['id' => 'app-as', 'default_ingress' => null],
        ], 201),
    ]);

    $site = makeContainerSite([
        'meta' => ['container' => [
            'instance_count' => 4,
            'autoscaling' => ['enabled' => true, 'min_instances' => 2, 'max_instances' => 6, 'cpu_percent' => 70],
        ]],
    ]);

    (new DigitalOceanAppPlatformBackend)->provision($site, credential());

    Http::assertSent(function ($req) {
        $service = $req->data()['spec']['services'][0] ?? [];
        $as = $service['autoscaling'] ?? null;

        return $as !== null
            && $as['min_instance_count'] === 2
            && $as['max_instance_count'] === 6
            && ($as['metrics']['cpu']['percent'] ?? null) === 70
            && ! array_key_exists('instance_count', $service);
    });
});
test('do provision keeps instance count when autoscaling disabled', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps' => Http::response([
            'app' => ['id' => 'app-fixed', 'default_ingress' => null],
        ], 201),
    ]);

    $site = makeContainerSite([
        'meta' => ['container' => ['instance_count' => 4]],
    ]);

    (new DigitalOceanAppPlatformBackend)->provision($site, credential());

    Http::assertSent(function ($req) {
        $service = $req->data()['spec']['services'][0] ?? [];

        return ($service['instance_count'] ?? null) === 4
            && ! array_key_exists('autoscaling', $service);
    });
});
test('do provision emits health check block when enabled', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps' => Http::response([
            'app' => ['id' => 'app-hc', 'default_ingress' => null],
        ], 201),
    ]);

    $site = makeContainerSite([
        'meta' => ['container' => [
            'health_check' => [
                'enabled' => true, 'http_path' => '/health',
                'initial_delay_seconds' => 5, 'period_seconds' => 12,
                'timeout_seconds' => 2, 'success_threshold' => 1, 'failure_threshold' => 4,
            ],
        ]],
    ]);

    (new DigitalOceanAppPlatformBackend)->provision($site, credential());

    Http::assertSent(function ($req) {
        $hc = $req->data()['spec']['services'][0]['health_check'] ?? null;

        return $hc !== null
            && $hc['http_path'] === '/health'
            && $hc['period_seconds'] === 12
            && $hc['failure_threshold'] === 4;
    });
});
test('do source provision emits autoscaling block', function () {
    Http::fake([
        'api.digitalocean.com/v2/apps' => Http::response([
            'app' => ['id' => 'app-src-as', 'default_ingress' => null],
        ], 201),
    ]);

    $site = makeContainerSite([
        'container_image' => null,
        'meta' => ['container' => [
            'source' => ['repo' => 'acme/api', 'branch' => 'main', 'deploy_on_push' => true],
            'autoscaling' => ['enabled' => true, 'min_instances' => 1, 'max_instances' => 4, 'cpu_percent' => 80],
        ]],
    ]);

    (new DigitalOceanAppPlatformBackend)->provisionFromSource($site, credential());

    Http::assertSent(function ($req) {
        $service = $req->data()['spec']['services'][0] ?? [];

        return isset($service['autoscaling'])
            && ! array_key_exists('instance_count', $service);
    });
});
test('do autoscaling survives redeploy', function () {
    $site = makeContainerSite([
        'container_backend_id' => 'app-existing',
        'meta' => ['container' => [
            'autoscaling' => ['enabled' => true, 'min_instances' => 2, 'max_instances' => 5, 'cpu_percent' => 60],
        ]],
    ]);

    Http::fake([
        'api.digitalocean.com/v2/apps/app-existing' => Http::response([
            'app' => ['spec' => [
                'name' => 'edge-app',
                'services' => [['name' => 'web', 'instance_count' => 1, 'image' => ['repository' => 'nginx', 'tag' => '1']]],
            ]],
        ], 200),
    ]);

    (new DigitalOceanAppPlatformBackend)->updateImage($site, credential(), 'nginx:2');

    Http::assertSent(function ($req) {
        if ($req->method() !== 'PUT') {
            return true;
        }
        $service = $req->data()['spec']['services'][0] ?? [];

        return isset($service['autoscaling'])
            && ! array_key_exists('instance_count', $service);
    });
});
test('do health check survives worker sync', function () {
    $site = makeContainerSite([
        'container_backend_id' => 'app-ws',
        'meta' => ['container' => [
            'health_check' => ['enabled' => true, 'http_path' => '/ping'],
        ]],
    ]);
    CloudWorker::factory()->create(['site_id' => $site->id]);

    Http::fake([
        'api.digitalocean.com/v2/apps/app-ws/deployments' => Http::response(['deployment' => ['id' => 'd1']], 200),
        'api.digitalocean.com/v2/apps/app-ws' => Http::response([
            'app' => ['spec' => [
                'name' => 'edge-app',
                'services' => [['name' => 'web', 'instance_count' => 1, 'image' => ['repository' => 'nginx', 'tag' => '1']]],
            ]],
        ], 200),
    ]);

    (new DigitalOceanAppPlatformBackend)->syncWorkers($site, credential());

    Http::assertSent(function ($req) {
        if ($req->method() !== 'PUT') {
            return true;
        }
        $hc = $req->data()['spec']['services'][0]['health_check'] ?? null;

        return $hc !== null && $hc['http_path'] === '/ping';
    });
});
test('do sync scaling pushes blocks and rolls deploy', function () {
    $site = makeContainerSite([
        'container_backend_id' => 'app-sync',
        'meta' => ['container' => [
            'autoscaling' => ['enabled' => true, 'min_instances' => 1, 'max_instances' => 3, 'cpu_percent' => 75],
        ]],
    ]);

    Http::fake([
        'api.digitalocean.com/v2/apps/app-sync/deployments' => Http::response(['deployment' => ['id' => 'd1']], 200),
        'api.digitalocean.com/v2/apps/app-sync' => Http::response([
            'app' => ['spec' => [
                'name' => 'edge-app',
                'services' => [['name' => 'web', 'instance_count' => 1, 'image' => ['repository' => 'nginx', 'tag' => '1']]],
            ]],
        ], 200),
    ]);

    (new DigitalOceanAppPlatformBackend)->syncScaling($site, credential());

    Http::assertSent(fn ($req) => $req->method() === 'PUT'
        && isset($req->data()['spec']['services'][0]['autoscaling']));
});
test('app runner supports autoscaling', function () {
    expect((new AwsAppRunnerBackend)->supportsAutoscaling())->toBeTrue();
});
test('app runner service update health check calls update service', function () {
    $client = Mockery::mock(AppRunnerClient::class);
    $client->shouldReceive('updateService')
        ->once()
        ->with(Mockery::on(function (array $args): bool {
            $hc = $args['HealthCheckConfiguration'] ?? null;

            return is_array($hc)
                && $hc['Protocol'] === 'HTTP'
                && $hc['Path'] === '/health'
                && $hc['Interval'] === 12
                && $hc['UnhealthyThreshold'] === 4;
        }));

    (new AwsAppRunnerService(awsCredential()))->withClient($client)->updateHealthCheck('arn:test', [
        'http_path' => '/health',
        'period_seconds' => 12,
        'timeout_seconds' => 2,
        'success_threshold' => 1,
        'failure_threshold' => 4,
    ]);
    expect(true)->toBeTrue();
});
test('app runner service apply autoscaling creates and associates config', function () {
    $client = Mockery::mock(AppRunnerClient::class);
    $client->shouldReceive('createAutoScalingConfiguration')
        ->once()
        ->with(Mockery::on(function (array $args): bool {
            return $args['MinSize'] === 2 && $args['MaxSize'] === 6;
        }))
        ->andReturn(new Result(['AutoScalingConfiguration' => [
            'AutoScalingConfigurationArn' => 'arn:aws:apprunner:us-east-1:1:autoscalingconfiguration/x',
        ]]));
    $client->shouldReceive('updateService')
        ->once()
        ->with(Mockery::on(function (array $args): bool {
            return ($args['AutoScalingConfigurationArn'] ?? null) === 'arn:aws:apprunner:us-east-1:1:autoscalingconfiguration/x';
        }));

    $arn = (new AwsAppRunnerService(awsCredential()))->withClient($client)
        ->applyAutoScaling('arn:svc', 'dply-test', 2, 6);

    $this->assertStringContainsString('autoscalingconfiguration', $arn);
});
test('app runner sync scaling no ops on unprovisioned site', function () {
    $site = makeContainerSite([
        'container_backend' => 'aws_app_runner',
        'container_backend_id' => null,
        'meta' => ['container' => ['autoscaling' => ['enabled' => true, 'min_instances' => 1, 'max_instances' => 3, 'cpu_percent' => 70]]],
    ]);

    // Unprovisioned — must return without touching the SDK.
    (new AwsAppRunnerBackend)->syncScaling($site, awsCredential());
    expect(true)->toBeTrue();
});
test('autoscale command enables and queues sync', function () {
    Queue::fake();
    config(['server_provision_fake.env_flag' => true]);
    $site = makeContainerSite();

    $exit = Artisan::call('dply:cloud:autoscale', [
        '--site' => $site->name,
        '--min' => '2',
        '--max' => '7',
        '--cpu' => '65',
    ]);

    expect($exit)->toBe(0);
    $config = CloudScalingConfig::autoscaling($site->fresh());
    expect($config['enabled'])->toBeTrue();
    expect($config['min_instances'])->toBe(2);
    expect($config['max_instances'])->toBe(7);
    expect($config['cpu_percent'])->toBe(65);
    Queue::assertPushed(SyncCloudScalingJob::class);
});
test('autoscale command off disables', function () {
    Queue::fake();
    config(['server_provision_fake.env_flag' => true]);
    $site = makeContainerSite([
        'meta' => ['container' => ['autoscaling' => ['enabled' => true, 'min_instances' => 1, 'max_instances' => 3, 'cpu_percent' => 80]]],
    ]);

    $exit = Artisan::call('dply:cloud:autoscale', ['--site' => $site->name, '--off' => true]);

    expect($exit)->toBe(0);
    expect(CloudScalingConfig::autoscaling($site->fresh())['enabled'])->toBeFalse();
    Queue::assertPushed(SyncCloudScalingJob::class);
});
test('autoscale command rejects invalid range', function () {
    config(['server_provision_fake.env_flag' => true]);
    $site = makeContainerSite();

    $exit = Artisan::call('dply:cloud:autoscale', [
        '--site' => $site->name,
        '--min' => '5',
        '--max' => '2',
    ]);

    expect($exit)->toBe(1);
});
test('autoscale command rejects non cloud site', function () {
    $user = User::factory()->create();
    $server = Server::factory()->ready()->create(['user_id' => $user->id]);
    $vmSite = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'php-site',
        'type' => SiteType::Php,
    ]);

    $exit = Artisan::call('dply:cloud:autoscale', ['--site' => $vmSite->name, '--min' => '1', '--max' => '3']);

    expect($exit)->toBe(1);
    $this->assertStringContainsString('not a cloud container site', Artisan::output());
});
test('scale command warns when autoscaling enabled', function () {
    Queue::fake();
    config(['server_provision_fake.env_flag' => true]);
    $site = makeContainerSite([
        'meta' => ['container' => ['autoscaling' => ['enabled' => true, 'min_instances' => 1, 'max_instances' => 3, 'cpu_percent' => 80]]],
    ]);

    Artisan::call('dply:cloud:scale', ['site' => $site->name, '--instances' => '3', '--no-redeploy' => true]);

    $this->assertStringContainsString('supersedes', Artisan::output());
});
test('healthcheck command enables and queues sync', function () {
    Queue::fake();
    config(['server_provision_fake.env_flag' => true]);
    $site = makeContainerSite();

    $exit = Artisan::call('dply:cloud:healthcheck', [
        '--site' => $site->name,
        '--path' => '/healthz',
        '--period' => '20',
        '--failure' => '6',
    ]);

    expect($exit)->toBe(0);
    $config = CloudScalingConfig::healthCheck($site->fresh());
    expect($config['enabled'])->toBeTrue();
    expect($config['http_path'])->toBe('/healthz');
    expect($config['period_seconds'])->toBe(20);
    expect($config['failure_threshold'])->toBe(6);
    Queue::assertPushed(SyncCloudScalingJob::class);
});
test('healthcheck command off disables', function () {
    Queue::fake();
    config(['server_provision_fake.env_flag' => true]);
    $site = makeContainerSite([
        'meta' => ['container' => ['health_check' => ['enabled' => true, 'http_path' => '/h']]],
    ]);

    $exit = Artisan::call('dply:cloud:healthcheck', ['--site' => $site->name, '--off' => true]);

    expect($exit)->toBe(0);
    expect(CloudScalingConfig::healthCheck($site->fresh())['enabled'])->toBeFalse();
});
test('healthcheck command rejects bad path', function () {
    config(['server_provision_fake.env_flag' => true]);
    $site = makeContainerSite();

    $exit = Artisan::call('dply:cloud:healthcheck', ['--site' => $site->name, '--path' => 'nope']);

    expect($exit)->toBe(1);
});
test('configure autoscaling action persists and dispatches', function () {
    Queue::fake();
    config(['server_provision_fake.env_flag' => true]);
    $site = makeContainerSite();

    (new ConfigureCloudAutoscaling)->handle($site, [
        'enabled' => true, 'min_instances' => 1, 'max_instances' => 4, 'cpu_percent' => 70,
    ]);

    expect(CloudScalingConfig::autoscaling($site->fresh())['enabled'])->toBeTrue();
    Queue::assertPushed(SyncCloudScalingJob::class);
});
test('configure health check action persists and dispatches', function () {
    Queue::fake();
    config(['server_provision_fake.env_flag' => true]);
    $site = makeContainerSite();

    (new ConfigureCloudHealthCheck)->handle($site, ['enabled' => true, 'http_path' => '/up']);

    expect(CloudScalingConfig::healthCheck($site->fresh())['http_path'])->toBe('/up');
    Queue::assertPushed(SyncCloudScalingJob::class);
});
test('dashboard renders scaling section', function () {
    [$user, $server, $site] = makeDashboardSite();

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

    $response->assertOk()
        ->assertSee('Scaling &amp; health', false)
        ->assertSee('Autoscaling')
        ->assertSee('HTTP health check');
});
test('dashboard autoscaling control dispatches action', function () {
    Queue::fake();
    [$user, $server, $site] = makeDashboardSite();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->set('container_autoscaling_enabled', true)
        ->set('container_autoscaling_min', 2)
        ->set('container_autoscaling_max', 6)
        ->set('container_autoscaling_cpu', 55)
        ->call('saveContainerAutoscaling');

    $config = CloudScalingConfig::autoscaling($site->fresh());
    expect($config['enabled'])->toBeTrue();
    expect($config['cpu_percent'])->toBe(55);
    Queue::assertPushed(SyncCloudScalingJob::class);
});
test('dashboard health check control dispatches action', function () {
    Queue::fake();
    [$user, $server, $site] = makeDashboardSite();

    Livewire::actingAs($user)
        ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->set('container_health_check_enabled', true)
        ->set('container_health_check_path', '/ready')
        ->call('saveContainerHealthCheck');

    expect(CloudScalingConfig::healthCheck($site->fresh())['http_path'])->toBe('/ready');
    Queue::assertPushed(SyncCloudScalingJob::class);
});
test('dashboard shows app runner note', function () {
    [$user, $server, $site] = makeDashboardSite('aws_app_runner');

    $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

    $response->assertOk()
        ->assertSee('Scaling &amp; health', false)
        ->assertSee('concurrency-driven');
});
/* ====================================================================
 * Helpers
 * ==================================================================== */
/**
 * @param  array<string, mixed>  $overrides
 */
function makeContainerSite(array $overrides = []): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $backend = $overrides['container_backend'] ?? 'digitalocean_app_platform';
    ProviderCredential::query()->firstOrCreate(
        ['organization_id' => $org->id, 'provider' => CloudRouter::credentialProviderFor($backend)],
        ['user_id' => $user->id, 'name' => 'cred', 'credentials' => ['api_token' => 'tok', 'access_key_id' => 'k', 'secret_access_key' => 's', 'github_connection_arn' => 'arn:x']],
    );
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
function makeDashboardSite(string $backend = 'digitalocean_app_platform'): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);
    ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => CloudRouter::credentialProviderFor($backend),
        'name' => 'cred',
        'credentials' => ['api_token' => 'tok', 'access_key_id' => 'k', 'secret_access_key' => 's', 'github_connection_arn' => 'arn:x'],
    ]);
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
        'container_backend' => $backend,
        'container_region' => 'nyc',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
    ]);

    return [$user, $server, $site];
}
function credential(): ProviderCredential
{
    return ProviderCredential::query()->where('provider', 'digitalocean')->firstOrFail();
}
function awsCredential(): ProviderCredential
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    return ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'aws_app_runner',
        'name' => 'aws',
        'credentials' => ['access_key_id' => 'k', 'secret_access_key' => 's', 'region' => 'us-east-1'],
    ]);
}
