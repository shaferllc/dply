<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\FleetExposureTest;

use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Models\Organization;
use App\Models\PrivateNetwork;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Services\FleetBackendExposure;
use App\Modules\Queue\Services\FleetWorkerEnvironment;
use App\Services\Servers\ManagedFirewallPort;
use App\Services\WorkerPools\SiteWorkerFleetTrustedSources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

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
