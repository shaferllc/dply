<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\FleetExposureTest;

use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Jobs\RunSetupScriptJob;
use App\Models\Organization;
use App\Models\PrivateNetwork;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Modules\Queue\Contracts\WorkerRuntime;
use App\Modules\Queue\Jobs\ExposeFleetBackendsJob;
use App\Modules\Queue\Jobs\PrepareFleetHostJob;
use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Services\FleetBackendExposure;
use App\Modules\Queue\Services\FleetReconciler;
use App\Modules\Queue\Services\FleetWorkerEnvironment;
use App\Modules\Queue\Support\WorkerHandle;
use App\Services\Servers\ManagedFirewallPort;
use App\Services\WorkerPools\SiteWorkerFleetTrustedSources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function exposureWith(array $env, ManagedFirewallPort $firewall): FleetBackendExposure
{
    $environment = \Mockery::mock(FleetWorkerEnvironment::class);
    $environment->shouldReceive('for')->andReturn($env);
    app()->instance(FleetWorkerEnvironment::class, $environment);
    app()->instance(ManagedFirewallPort::class, $firewall);
    app()->instance(SiteWorkerFleetTrustedSources::class, \Mockery::mock(SiteWorkerFleetTrustedSources::class)->shouldIgnoreMissing());

    return app(FleetBackendExposure::class);
}

test('a backend in the fleet hosts’ private network opens to their private addresses only', function () {
    $vpc = PrivateNetwork::query()->forceCreate([
        'organization_id' => Organization::factory()->create()->id,
        'name' => 'vpc-1',
        'provider' => 'digitalocean',
    ]);
    $db = Server::factory()->create(['private_ip_address' => '10.0.0.5', 'private_network_id' => $vpc->id]);
    $host = Server::factory()->create([
        'private_ip_address' => '10.0.0.9',
        'private_network_id' => $vpc->id,
        'meta' => ['queue_fleet_host' => ['enabled' => true, 'capacity_mib' => 3072]],
    ]);

    $firewall = \Mockery::mock(ManagedFirewallPort::class);
    $firewall->shouldReceive('openGroup')->once()->withArgs(
        fn (Server $server, string $tag, int $port, array $sources): bool => $server->is($db)
            && $tag === 'dply-queue-fleet-3306'
            && $port === 3306
            && $sources === [(string) $host->id => '10.0.0.9/32'],
    );

    $result = exposureWith(['DB_CONNECTION' => 'mysql', 'DB_HOST' => '10.0.0.5', 'REDIS_HOST' => '127.0.0.1'], $firewall)
        ->open(new ManagedQueueFleet, $host);

    // Loopback is reported, never touched.
    expect(array_column($result, 'action', 'name'))->toBe(['database' => 'firewall', 'redis' => 'loopback']);
});

test('a backend outside any dply private network is left alone', function () {
    $host = Server::factory()->create(['meta' => ['queue_fleet_host' => ['enabled' => true, 'capacity_mib' => 3072]]]);
    $firewall = \Mockery::mock(ManagedFirewallPort::class);
    $firewall->shouldNotReceive('openGroup');

    $result = exposureWith(['DB_CONNECTION' => 'mysql', 'DB_HOST' => 'db.example.com'], $firewall)
        ->open(new ManagedQueueFleet, $host);

    expect($result[0]['action'])->toBe('unmanaged');
});

test('a provisioned fleet host prepares itself: docker, opt-in, then the smoke test', function () {
    $server = Server::factory()->create(['meta' => ['queue_fleet_host' => ['pending' => ['capacity_mib' => 3072, 'smoke_site_id' => 'site-1']]]]);

    Artisan::shouldReceive('call')->once()->with('dply:queue:fleet-host', ['server' => (string) $server->id, '--capacity' => 3072])->andReturn(0);
    Artisan::shouldReceive('call')->once()->with('dply:queue:fleet-smoke', ['server' => (string) $server->id, '--site' => 'site-1'])->andReturn(0);
    Artisan::shouldReceive('output')->andReturn('ok');

    (new PrepareFleetHostJob((string) $server->id))->handle();

    $host = $server->fresh()->meta['queue_fleet_host'];
    expect($host)->not->toHaveKey('pending')
        ->and($host['preparation']['opt_in']['ok'])->toBeTrue()
        ->and($host['preparation']['smoke']['ok'])->toBeTrue();
});

test('a host whose opt-in fails is not smoke-tested and stays pending', function () {
    $server = Server::factory()->create(['meta' => ['queue_fleet_host' => ['pending' => ['capacity_mib' => 3072, 'smoke_site_id' => 'site-1']]]]);

    Artisan::shouldReceive('call')->once()->with('dply:queue:fleet-host', \Mockery::any())->andReturn(1);
    Artisan::shouldReceive('output')->andReturn('Docker did not install');

    (new PrepareFleetHostJob((string) $server->id))->handle();

    $host = $server->fresh()->meta['queue_fleet_host'];
    expect($host)->toHaveKey('pending')
        ->and($host['preparation']['opt_in']['ok'])->toBeFalse();
});

test('provisioning finishing on a pending fleet host starts its preparation', function () {
    Bus::fake([PrepareFleetHostJob::class]);
    $server = Server::factory()->create(['meta' => ['queue_fleet_host' => ['pending' => ['capacity_mib' => 3072]]]]);
    $plain = Server::factory()->create();

    RunSetupScriptJob::applyProvisionOutcomeToServer($server, true);
    RunSetupScriptJob::applyProvisionOutcomeToServer($plain, true);

    Bus::assertDispatchedTimes(PrepareFleetHostJob::class, 1);
    Bus::assertDispatched(PrepareFleetHostJob::class, fn ($job): bool => $job->serverId === (string) $server->id);
});

test('the first worker a fleet places on a host opens that host to the backends, once', function () {
    Bus::fake([ExposeFleetBackendsJob::class]);
    config(['queue_service.public_url' => 'https://queue.dply.test/api/queue/v1']);
    $host = Server::factory()->create();
    $runtime = \Mockery::mock(WorkerRuntime::class);
    $runtime->shouldReceive('name')->andReturn('docker');
    $runtime->shouldReceive('isAlive')->andReturn(true);
    $runtime->shouldReceive('start')->andReturnUsing(fn () => new WorkerHandle((string) Str::ulid(), 'docker', (string) $host->id));
    app()->instance(WorkerRuntime::class, $runtime);

    $org = Organization::factory()->create();
    $namespace = QueueNamespace::query()->create(['organization_id' => $org->id, 'name' => 'orders', 'status' => QueueNamespace::STATUS_ACTIVE]);
    $fleet = ManagedQueueFleet::query()->create([
        'namespace_id' => $namespace->id, 'organization_id' => $org->id, 'queue' => 'default',
        'class' => ManagedQueueFleet::CLASS_FLEX, 'status' => ManagedQueueFleet::STATUS_ACTIVE,
        'memory_mib' => 256, 'min_workers' => 1, 'max_workers' => 2, 'image' => 'app:1',
    ]);

    app(FleetReconciler::class)->reconcile($fleet);
    Bus::assertDispatched(ExposeFleetBackendsJob::class, fn ($job): bool => $job->hostId === (string) $host->id);

    // Once the exposure is recorded, later placements on that host skip it.
    $fleet->refresh()->forceFill(['meta' => ['exposed_hosts' => [(string) $host->id]]])->save();
    Bus::fake([ExposeFleetBackendsJob::class]);
    app(FleetReconciler::class)->wake($fleet->fresh());
    Bus::assertNotDispatched(ExposeFleetBackendsJob::class);
});

test('fleet-host-create provisions a droplet beside the app database', function () {
    Bus::fake([ProvisionDigitalOceanDropletJob::class]);
    $owner = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);
    $credential = ProviderCredential::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $owner->id,
        'provider' => 'digitalocean',
    ]);
    Server::factory()->create(['name' => 'dply-app', 'region' => 'nyc3', 'meta' => ['digitalocean' => ['vpc_uuid' => 'vpc-uuid-1']]]);

    $this->artisan('dply:queue:fleet-host-create', ['name' => 'dply-fleet-1', '--credential' => $credential->id, '--near' => 'dply-app'])
        ->assertSuccessful();

    $server = Server::query()->where('name', 'dply-fleet-1')->firstOrFail();
    expect($server->region)->toBe('nyc3')
        ->and($server->size)->toBe('s-2vcpu-4gb')
        ->and(data_get($server->meta, 'digitalocean.vpc_uuid'))->toBe('vpc-uuid-1');
    Bus::assertDispatched(ProvisionDigitalOceanDropletJob::class, fn ($job): bool => $job->server->is($server));
});
