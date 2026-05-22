<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Cloud\ConfigureCloudAutoscaling;
use App\Actions\Cloud\ConfigureCloudHealthCheck;
use App\Enums\SiteType;
use App\Jobs\SyncCloudScalingJob;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\CloudWorker;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\AwsAppRunnerService;
use App\Services\Cloud\AwsAppRunnerBackend;
use App\Services\Cloud\CloudScalingConfig;
use App\Services\Cloud\DigitalOceanAppPlatformBackend;
use Aws\AppRunner\AppRunnerClient;
use Aws\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Coverage for the autoscaling & health-check feature:
 *  - CloudScalingConfig validation + meta read/write.
 *  - DigitalOcean App Platform spec emits the `autoscaling` block
 *    (and omits the fixed `instance_count`) and the `health_check`
 *    block when enabled; both survive redeploys + worker syncs.
 *  - AWS App Runner applies a HealthCheckConfiguration and an
 *    AutoScalingConfiguration, degrading gracefully.
 *  - The dply:cloud:autoscale / dply:cloud:healthcheck commands.
 *  - The dashboard Scaling & health section.
 */
class CloudAutoscalingTest extends TestCase
{
    use RefreshDatabase;

    /* ====================================================================
     * Config validation
     * ==================================================================== */

    public function test_autoscaling_validation_rejects_min_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CloudScalingConfig::validateAutoscaling(['enabled' => true, 'min_instances' => 0, 'max_instances' => 3, 'cpu_percent' => 70]);
    }

    public function test_autoscaling_validation_rejects_max_below_min(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CloudScalingConfig::validateAutoscaling(['enabled' => true, 'min_instances' => 5, 'max_instances' => 2, 'cpu_percent' => 70]);
    }

    public function test_autoscaling_validation_rejects_cpu_out_of_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CloudScalingConfig::validateAutoscaling(['enabled' => true, 'min_instances' => 1, 'max_instances' => 3, 'cpu_percent' => 150]);
    }

    public function test_autoscaling_validation_accepts_valid_config(): void
    {
        $config = CloudScalingConfig::validateAutoscaling(['enabled' => true, 'min_instances' => 2, 'max_instances' => 8, 'cpu_percent' => 65]);
        $this->assertSame(2, $config['min_instances']);
        $this->assertSame(8, $config['max_instances']);
        $this->assertSame(65, $config['cpu_percent']);
        $this->assertTrue($config['enabled']);
    }

    public function test_health_check_validation_rejects_path_without_slash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CloudScalingConfig::validateHealthCheck(['enabled' => true, 'http_path' => 'health']);
    }

    public function test_health_check_validation_rejects_non_positive_threshold(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CloudScalingConfig::validateHealthCheck(['enabled' => true, 'http_path' => '/health', 'period_seconds' => 0]);
    }

    public function test_health_check_validation_accepts_valid_config(): void
    {
        $config = CloudScalingConfig::validateHealthCheck([
            'enabled' => true,
            'http_path' => '/up',
            'initial_delay_seconds' => 5,
            'period_seconds' => 15,
            'timeout_seconds' => 3,
            'success_threshold' => 2,
            'failure_threshold' => 5,
        ]);
        $this->assertSame('/up', $config['http_path']);
        $this->assertSame(15, $config['period_seconds']);
        $this->assertSame(5, $config['failure_threshold']);
    }

    public function test_config_defaults_when_meta_absent(): void
    {
        $site = $this->makeContainerSite();
        $autoscaling = CloudScalingConfig::autoscaling($site);
        $healthCheck = CloudScalingConfig::healthCheck($site);

        $this->assertFalse($autoscaling['enabled']);
        $this->assertFalse($healthCheck['enabled']);
        $this->assertSame(CloudScalingConfig::DEFAULT_HEALTH_PATH, $healthCheck['http_path']);
    }

    /* ====================================================================
     * DigitalOcean App Platform spec
     * ==================================================================== */

    public function test_do_provision_emits_autoscaling_block_and_omits_instance_count(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps' => Http::response([
                'app' => ['id' => 'app-as', 'default_ingress' => null],
            ], 201),
        ]);

        $site = $this->makeContainerSite([
            'meta' => ['container' => [
                'instance_count' => 4,
                'autoscaling' => ['enabled' => true, 'min_instances' => 2, 'max_instances' => 6, 'cpu_percent' => 70],
            ]],
        ]);

        (new DigitalOceanAppPlatformBackend)->provision($site, $this->credential());

        Http::assertSent(function ($req) {
            $service = $req->data()['spec']['services'][0] ?? [];
            $as = $service['autoscaling'] ?? null;

            return $as !== null
                && $as['min_instance_count'] === 2
                && $as['max_instance_count'] === 6
                && ($as['metrics']['cpu']['percent'] ?? null) === 70
                && ! array_key_exists('instance_count', $service);
        });
    }

    public function test_do_provision_keeps_instance_count_when_autoscaling_disabled(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps' => Http::response([
                'app' => ['id' => 'app-fixed', 'default_ingress' => null],
            ], 201),
        ]);

        $site = $this->makeContainerSite([
            'meta' => ['container' => ['instance_count' => 4]],
        ]);

        (new DigitalOceanAppPlatformBackend)->provision($site, $this->credential());

        Http::assertSent(function ($req) {
            $service = $req->data()['spec']['services'][0] ?? [];

            return ($service['instance_count'] ?? null) === 4
                && ! array_key_exists('autoscaling', $service);
        });
    }

    public function test_do_provision_emits_health_check_block_when_enabled(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps' => Http::response([
                'app' => ['id' => 'app-hc', 'default_ingress' => null],
            ], 201),
        ]);

        $site = $this->makeContainerSite([
            'meta' => ['container' => [
                'health_check' => [
                    'enabled' => true, 'http_path' => '/health',
                    'initial_delay_seconds' => 5, 'period_seconds' => 12,
                    'timeout_seconds' => 2, 'success_threshold' => 1, 'failure_threshold' => 4,
                ],
            ]],
        ]);

        (new DigitalOceanAppPlatformBackend)->provision($site, $this->credential());

        Http::assertSent(function ($req) {
            $hc = $req->data()['spec']['services'][0]['health_check'] ?? null;

            return $hc !== null
                && $hc['http_path'] === '/health'
                && $hc['period_seconds'] === 12
                && $hc['failure_threshold'] === 4;
        });
    }

    public function test_do_source_provision_emits_autoscaling_block(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/apps' => Http::response([
                'app' => ['id' => 'app-src-as', 'default_ingress' => null],
            ], 201),
        ]);

        $site = $this->makeContainerSite([
            'container_image' => null,
            'meta' => ['container' => [
                'source' => ['repo' => 'acme/api', 'branch' => 'main', 'deploy_on_push' => true],
                'autoscaling' => ['enabled' => true, 'min_instances' => 1, 'max_instances' => 4, 'cpu_percent' => 80],
            ]],
        ]);

        (new DigitalOceanAppPlatformBackend)->provisionFromSource($site, $this->credential());

        Http::assertSent(function ($req) {
            $service = $req->data()['spec']['services'][0] ?? [];

            return isset($service['autoscaling'])
                && ! array_key_exists('instance_count', $service);
        });
    }

    public function test_do_autoscaling_survives_redeploy(): void
    {
        $site = $this->makeContainerSite([
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

        (new DigitalOceanAppPlatformBackend)->updateImage($site, $this->credential(), 'nginx:2');

        Http::assertSent(function ($req) {
            if ($req->method() !== 'PUT') {
                return true;
            }
            $service = $req->data()['spec']['services'][0] ?? [];

            return isset($service['autoscaling'])
                && ! array_key_exists('instance_count', $service);
        });
    }

    public function test_do_health_check_survives_worker_sync(): void
    {
        $site = $this->makeContainerSite([
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

        (new DigitalOceanAppPlatformBackend)->syncWorkers($site, $this->credential());

        Http::assertSent(function ($req) {
            if ($req->method() !== 'PUT') {
                return true;
            }
            $hc = $req->data()['spec']['services'][0]['health_check'] ?? null;

            return $hc !== null && $hc['http_path'] === '/ping';
        });
    }

    public function test_do_sync_scaling_pushes_blocks_and_rolls_deploy(): void
    {
        $site = $this->makeContainerSite([
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

        (new DigitalOceanAppPlatformBackend)->syncScaling($site, $this->credential());

        Http::assertSent(fn ($req) => $req->method() === 'PUT'
            && isset($req->data()['spec']['services'][0]['autoscaling']));
    }

    /* ====================================================================
     * AWS App Runner
     * ==================================================================== */

    public function test_app_runner_supports_autoscaling(): void
    {
        $this->assertTrue((new AwsAppRunnerBackend)->supportsAutoscaling());
    }

    public function test_app_runner_service_update_health_check_calls_update_service(): void
    {
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

        (new AwsAppRunnerService($this->awsCredential()))->withClient($client)->updateHealthCheck('arn:test', [
            'http_path' => '/health',
            'period_seconds' => 12,
            'timeout_seconds' => 2,
            'success_threshold' => 1,
            'failure_threshold' => 4,
        ]);
        $this->assertTrue(true);
    }

    public function test_app_runner_service_apply_autoscaling_creates_and_associates_config(): void
    {
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

        $arn = (new AwsAppRunnerService($this->awsCredential()))->withClient($client)
            ->applyAutoScaling('arn:svc', 'dply-test', 2, 6);

        $this->assertStringContainsString('autoscalingconfiguration', $arn);
    }

    public function test_app_runner_sync_scaling_no_ops_on_unprovisioned_site(): void
    {
        $site = $this->makeContainerSite([
            'container_backend' => 'aws_app_runner',
            'container_backend_id' => null,
            'meta' => ['container' => ['autoscaling' => ['enabled' => true, 'min_instances' => 1, 'max_instances' => 3, 'cpu_percent' => 70]]],
        ]);

        // Unprovisioned — must return without touching the SDK.
        (new AwsAppRunnerBackend)->syncScaling($site, $this->awsCredential());
        $this->assertTrue(true);
    }

    /* ====================================================================
     * CLI — dply:cloud:autoscale
     * ==================================================================== */

    public function test_autoscale_command_enables_and_queues_sync(): void
    {
        Queue::fake();
        config(['server_provision_fake.env_flag' => true]);
        $site = $this->makeContainerSite();

        $exit = Artisan::call('dply:cloud:autoscale', [
            '--site' => $site->name,
            '--min' => '2',
            '--max' => '7',
            '--cpu' => '65',
        ]);

        $this->assertSame(0, $exit);
        $config = CloudScalingConfig::autoscaling($site->fresh());
        $this->assertTrue($config['enabled']);
        $this->assertSame(2, $config['min_instances']);
        $this->assertSame(7, $config['max_instances']);
        $this->assertSame(65, $config['cpu_percent']);
        Queue::assertPushed(SyncCloudScalingJob::class);
    }

    public function test_autoscale_command_off_disables(): void
    {
        Queue::fake();
        config(['server_provision_fake.env_flag' => true]);
        $site = $this->makeContainerSite([
            'meta' => ['container' => ['autoscaling' => ['enabled' => true, 'min_instances' => 1, 'max_instances' => 3, 'cpu_percent' => 80]]],
        ]);

        $exit = Artisan::call('dply:cloud:autoscale', ['--site' => $site->name, '--off' => true]);

        $this->assertSame(0, $exit);
        $this->assertFalse(CloudScalingConfig::autoscaling($site->fresh())['enabled']);
        Queue::assertPushed(SyncCloudScalingJob::class);
    }

    public function test_autoscale_command_rejects_invalid_range(): void
    {
        config(['server_provision_fake.env_flag' => true]);
        $site = $this->makeContainerSite();

        $exit = Artisan::call('dply:cloud:autoscale', [
            '--site' => $site->name,
            '--min' => '5',
            '--max' => '2',
        ]);

        $this->assertSame(1, $exit);
    }

    public function test_autoscale_command_rejects_non_cloud_site(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->ready()->create(['user_id' => $user->id]);
        $vmSite = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'name' => 'php-site',
            'type' => SiteType::Php,
        ]);

        $exit = Artisan::call('dply:cloud:autoscale', ['--site' => $vmSite->name, '--min' => '1', '--max' => '3']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not a cloud container site', Artisan::output());
    }

    public function test_scale_command_warns_when_autoscaling_enabled(): void
    {
        Queue::fake();
        config(['server_provision_fake.env_flag' => true]);
        $site = $this->makeContainerSite([
            'meta' => ['container' => ['autoscaling' => ['enabled' => true, 'min_instances' => 1, 'max_instances' => 3, 'cpu_percent' => 80]]],
        ]);

        Artisan::call('dply:cloud:scale', ['site' => $site->name, '--instances' => '3', '--no-redeploy' => true]);

        $this->assertStringContainsString('supersedes', Artisan::output());
    }

    /* ====================================================================
     * CLI — dply:cloud:healthcheck
     * ==================================================================== */

    public function test_healthcheck_command_enables_and_queues_sync(): void
    {
        Queue::fake();
        config(['server_provision_fake.env_flag' => true]);
        $site = $this->makeContainerSite();

        $exit = Artisan::call('dply:cloud:healthcheck', [
            '--site' => $site->name,
            '--path' => '/healthz',
            '--period' => '20',
            '--failure' => '6',
        ]);

        $this->assertSame(0, $exit);
        $config = CloudScalingConfig::healthCheck($site->fresh());
        $this->assertTrue($config['enabled']);
        $this->assertSame('/healthz', $config['http_path']);
        $this->assertSame(20, $config['period_seconds']);
        $this->assertSame(6, $config['failure_threshold']);
        Queue::assertPushed(SyncCloudScalingJob::class);
    }

    public function test_healthcheck_command_off_disables(): void
    {
        Queue::fake();
        config(['server_provision_fake.env_flag' => true]);
        $site = $this->makeContainerSite([
            'meta' => ['container' => ['health_check' => ['enabled' => true, 'http_path' => '/h']]],
        ]);

        $exit = Artisan::call('dply:cloud:healthcheck', ['--site' => $site->name, '--off' => true]);

        $this->assertSame(0, $exit);
        $this->assertFalse(CloudScalingConfig::healthCheck($site->fresh())['enabled']);
    }

    public function test_healthcheck_command_rejects_bad_path(): void
    {
        config(['server_provision_fake.env_flag' => true]);
        $site = $this->makeContainerSite();

        $exit = Artisan::call('dply:cloud:healthcheck', ['--site' => $site->name, '--path' => 'nope']);

        $this->assertSame(1, $exit);
    }

    /* ====================================================================
     * Actions
     * ==================================================================== */

    public function test_configure_autoscaling_action_persists_and_dispatches(): void
    {
        Queue::fake();
        config(['server_provision_fake.env_flag' => true]);
        $site = $this->makeContainerSite();

        (new ConfigureCloudAutoscaling)->handle($site, [
            'enabled' => true, 'min_instances' => 1, 'max_instances' => 4, 'cpu_percent' => 70,
        ]);

        $this->assertTrue(CloudScalingConfig::autoscaling($site->fresh())['enabled']);
        Queue::assertPushed(SyncCloudScalingJob::class);
    }

    public function test_configure_health_check_action_persists_and_dispatches(): void
    {
        Queue::fake();
        config(['server_provision_fake.env_flag' => true]);
        $site = $this->makeContainerSite();

        (new ConfigureCloudHealthCheck)->handle($site, ['enabled' => true, 'http_path' => '/up']);

        $this->assertSame('/up', CloudScalingConfig::healthCheck($site->fresh())['http_path']);
        Queue::assertPushed(SyncCloudScalingJob::class);
    }

    /* ====================================================================
     * Dashboard — Scaling & health section
     * ==================================================================== */

    public function test_dashboard_renders_scaling_section(): void
    {
        [$user, $server, $site] = $this->makeDashboardSite();

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertSee('Scaling &amp; health', false)
            ->assertSee('Autoscaling')
            ->assertSee('HTTP health check');
    }

    public function test_dashboard_autoscaling_control_dispatches_action(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeDashboardSite();

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->set('container_autoscaling_enabled', true)
            ->set('container_autoscaling_min', 2)
            ->set('container_autoscaling_max', 6)
            ->set('container_autoscaling_cpu', 55)
            ->call('saveContainerAutoscaling');

        $config = CloudScalingConfig::autoscaling($site->fresh());
        $this->assertTrue($config['enabled']);
        $this->assertSame(55, $config['cpu_percent']);
        Queue::assertPushed(SyncCloudScalingJob::class);
    }

    public function test_dashboard_health_check_control_dispatches_action(): void
    {
        Queue::fake();
        [$user, $server, $site] = $this->makeDashboardSite();

        Livewire::actingAs($user)
            ->test(SiteSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
            ->set('container_health_check_enabled', true)
            ->set('container_health_check_path', '/ready')
            ->call('saveContainerHealthCheck');

        $this->assertSame('/ready', CloudScalingConfig::healthCheck($site->fresh())['http_path']);
        Queue::assertPushed(SyncCloudScalingJob::class);
    }

    public function test_dashboard_shows_app_runner_note(): void
    {
        [$user, $server, $site] = $this->makeDashboardSite('aws_app_runner');

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertSee('Scaling &amp; health', false)
            ->assertSee('concurrency-driven');
    }

    /* ====================================================================
     * Helpers
     * ==================================================================== */

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeContainerSite(array $overrides = []): Site
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        ProviderCredential::query()->firstOrCreate(
            ['organization_id' => $org->id, 'provider' => $overrides['container_backend'] ?? 'digitalocean_app_platform'],
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
    private function makeDashboardSite(string $backend = 'digitalocean_app_platform'): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);
        ProviderCredential::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'provider' => $backend,
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

    private function credential(): ProviderCredential
    {
        return ProviderCredential::query()->where('provider', 'digitalocean_app_platform')->firstOrFail();
    }

    private function awsCredential(): ProviderCredential
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
}
