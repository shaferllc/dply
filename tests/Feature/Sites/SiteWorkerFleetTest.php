<?php

use App\Enums\ServerProvider;
use App\Jobs\DrainAndDestroyWorkerJob;
use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Jobs\ProvisionSiteJob;
use App\Jobs\ProvisionSiteSystemdUnitsJob;
use App\Jobs\PushSiteEnvJob;
use App\Jobs\ReconcileWorkerPoolJob;
use App\Livewire\Servers\WorkspaceOverview;
use App\Livewire\Servers\WorkspaceSites;
use App\Livewire\Servers\WorkspaceWorkerPool;
use App\Livewire\Sites\Settings as SiteSettings;
use App\Models\CloudDatabase;
use App\Models\ConsoleAction;
use App\Models\Organization;
use App\Models\PrivateNetwork;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\SiteProcess;
use App\Models\SupervisorProgram;
use App\Models\User;
use App\Models\WorkerPool;
use App\Services\WorkerPools\SiteWorkerFleetOnBoxDaemons;
use App\Services\WorkerPools\SiteWorkerFleetPreflight;
use App\Services\WorkerPools\WorkerCloneProvisioner;
use App\Services\WorkerPools\WorkerPoolManager;
use App\Services\WorkerPools\WorkerWorkloadReplayer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake([
        'api.digitalocean.com/*' => Http::response(['account' => ['uuid' => 'ok']], 200),
    ]);
});

/** @return array{0: User, 1: Organization} */
function fleetActor(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    return [$user, $org];
}

function fleetNetwork(Organization $org): PrivateNetwork
{
    return PrivateNetwork::query()->create([
        'organization_id' => $org->id,
        'name' => 'app-vpc',
        'provider' => PrivateNetwork::PROVIDER_DO,
        'ip_range' => '10.10.0.0/16',
    ]);
}

/**
 * @return array{0: User, 1: Organization, 2: PrivateNetwork, 3: Server, 4: Server, 5: Site, 6: ProviderCredential}
 */
function fleetReadySite(array $overrides = []): array
{
    [$user, $org] = fleetActor();
    $network = fleetNetwork($org);
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
    ]);

    $app = Server::factory()->digitalOcean()->create(array_merge([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'dply-app',
        'provider_credential_id' => $credential->id,
        'size' => 's-2vcpu-4gb',
        'ip_address' => '203.0.113.10',
        'private_ip_address' => '10.10.0.10',
        'private_network_id' => $network->id,
        'meta' => ['server_role' => 'application', 'host_kind' => 'vm'],
    ], $overrides['app'] ?? []));

    $redis = Server::factory()->digitalOcean()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'dply-redis',
        'ip_address' => '203.0.113.20',
        'private_ip_address' => '10.10.0.20',
        'private_network_id' => $network->id,
        'meta' => ['server_role' => 'redis', 'host_kind' => 'vm'],
    ]);

    $site = Site::factory()->create([
        'server_id' => $app->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'App',
        'env_file_content' => "REDIS_HOST=10.10.0.20\nDB_HOST=10.10.0.20\nDPLY_WORKER_ROLE=primary\n",
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);

    return [$user, $org, $network, $app, $redis, $site, $credential];
}

it('refuses add-worker preflight when a VM Redis is not on a shared VPC', function () {
    [$user, $org] = fleetActor();
    $app = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'private_network_id' => null,
        'hetzner_network_id' => null,
    ]);
    Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'name' => 'lonely-redis',
        'private_ip_address' => '10.10.0.20',
        'private_network_id' => null,
    ]);
    $site = Site::factory()->create([
        'server_id' => $app->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'env_file_content' => "REDIS_HOST=10.10.0.20\nDB_HOST=10.10.0.20\n",
    ]);

    $result = app(SiteWorkerFleetPreflight::class)->evaluate($site);

    expect($result->ok)->toBeFalse()
        ->and($result->allowsRemoteRegion)->toBeFalse()
        ->and($result->message)->toContain('private network');
});

it('refuses add-worker preflight when Redis is localhost', function () {
    [$user, $org] = fleetActor();
    $network = fleetNetwork($org);
    $app = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'private_network_id' => $network->id,
        'private_ip_address' => '10.10.0.10',
    ]);
    $site = Site::factory()->create([
        'server_id' => $app->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'env_file_content' => "REDIS_HOST=127.0.0.1\nDB_HOST=127.0.0.1\n",
    ]);

    $result = app(SiteWorkerFleetPreflight::class)->evaluate($site);

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('web box');
});

it('refuses add-worker preflight when Redis is on a different VPC', function () {
    [$user, $org] = fleetActor();
    $appNet = fleetNetwork($org);
    $otherNet = PrivateNetwork::query()->create([
        'organization_id' => $org->id,
        'name' => 'other-vpc',
        'provider' => PrivateNetwork::PROVIDER_DO,
        'ip_range' => '10.20.0.0/16',
    ]);
    $app = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'private_network_id' => $appNet->id,
        'private_ip_address' => '10.10.0.10',
    ]);
    Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'private_network_id' => $otherNet->id,
        'private_ip_address' => '10.20.0.20',
        'name' => 'lonely-redis',
    ]);
    $site = Site::factory()->create([
        'server_id' => $app->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'env_file_content' => "REDIS_HOST=10.20.0.20\nDB_HOST=10.20.0.20\n",
    ]);

    $result = app(SiteWorkerFleetPreflight::class)->evaluate($site);

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('not on the same private network');
});

it('passes add-worker preflight when Redis and the database are DigitalOcean managed', function () {
    [, $org, $network, , , $site] = fleetReadySite();
    $site->forceFill(['env_file_content' => "APP_ENV=production\n"])->save();

    $database = CloudDatabase::factory()->active()->create([
        'organization_id' => $org->id,
        'name' => 'app-pg',
    ]);
    $redis = CloudDatabase::factory()->active()->redis()->create([
        'organization_id' => $org->id,
        'name' => 'app-valkey',
    ]);

    SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'database',
        'mode' => 'managed',
        'status' => SiteBinding::STATUS_CONFIGURED,
        'name' => 'primary',
        'target_type' => 'cloud_database',
        'target_id' => $database->id,
        'injected_env' => [],
    ]);
    SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'redis',
        'mode' => 'managed',
        'status' => SiteBinding::STATUS_CONFIGURED,
        'name' => 'primary',
        'target_type' => 'cloud_database',
        'target_id' => $redis->id,
        'injected_env' => [],
    ]);

    $result = app(SiteWorkerFleetPreflight::class)->evaluate($site->fresh());

    expect($result->ok)->toBeTrue()
        ->and($result->allowsRemoteRegion)->toBeTrue()
        ->and($result->networkName)->toBe($network->name)
        ->and($result->message)->toContain('managed')
        ->and(collect($result->backends)->pluck('name'))->toContain('app-pg')->toContain('app-valkey');
});

it('passes add-worker preflight when env points at DigitalOcean managed hosts', function () {
    [, , , , , $site] = fleetReadySite();
    $site->forceFill([
        'env_file_content' => "REDIS_HOST=app-valkey.db.ondigitalocean.com\nDB_HOST=app-pg.db.ondigitalocean.com\n",
    ])->save();

    $result = app(SiteWorkerFleetPreflight::class)->evaluate($site->fresh());

    expect($result->ok)->toBeTrue()
        ->and($result->allowsRemoteRegion)->toBeTrue()
        ->and($result->message)->toContain('managed');
});

it('passes add-worker preflight for managed Redis/database without a site VPC', function () {
    [$user, $org] = fleetActor();
    $app = Server::factory()->digitalOcean()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'private_network_id' => null,
        'hetzner_network_id' => null,
        'region' => 'nyc1',
    ]);
    $site = Site::factory()->create([
        'server_id' => $app->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'env_file_content' => "APP_ENV=production\n",
        'status' => Site::STATUS_NGINX_ACTIVE,
    ]);
    $database = CloudDatabase::factory()->active()->create([
        'organization_id' => $org->id,
        'name' => 'app-pg',
    ]);
    $redis = CloudDatabase::factory()->active()->redis()->create([
        'organization_id' => $org->id,
        'name' => 'app-valkey',
    ]);
    SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'database',
        'mode' => 'managed',
        'status' => SiteBinding::STATUS_CONFIGURED,
        'name' => 'primary',
        'target_type' => 'cloud_database',
        'target_id' => $database->id,
        'injected_env' => [],
    ]);
    SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'redis',
        'mode' => 'managed',
        'status' => SiteBinding::STATUS_CONFIGURED,
        'name' => 'primary',
        'target_type' => 'cloud_database',
        'target_id' => $redis->id,
        'injected_env' => [],
    ]);

    $result = app(SiteWorkerFleetPreflight::class)->evaluate($site->fresh());

    expect($result->ok)->toBeTrue()
        ->and($result->allowsRemoteRegion)->toBeTrue()
        ->and($result->networkName)->toBeNull();
});

it('refuses add-worker preflight when the DigitalOcean token is rejected', function () {
    [, , , $app, , $site] = fleetReadySite();
    $app->providerCredential?->forceFill([
        'last_validated_at' => now(),
        'validation_error' => 'DigitalOcean API failed to validate token: Unable to authenticate you',
    ])->save();

    $result = app(SiteWorkerFleetPreflight::class)->evaluate($site);

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('rejected this API token')
        ->and($app->providerCredential?->fresh()?->isUnhealthy())->toBeTrue();
});

it('passes add-worker preflight when the site and Redis share a VPC', function () {
    [, , $network, , $redis, $site] = fleetReadySite();

    $result = app(SiteWorkerFleetPreflight::class)->evaluate($site);

    expect($result->ok)->toBeTrue()
        ->and($result->allowsRemoteRegion)->toBeFalse()
        ->and($result->networkName)->toBe('app-vpc')
        ->and($result->backends)->not->toBeEmpty()
        ->and(collect($result->backends)->pluck('id'))->toContain($redis->id)
        ->and($result->message)->toContain($network->name);
});

it('creates a site-sourced pool and provisions the first worker', function () {
    Queue::fake();
    [$user, , $network, $app, , $site] = fleetReadySite();

    $pool = app(WorkerPoolManager::class)->createPoolFromSite($user, $site, 's-2vcpu-2gb', true);

    expect($pool->isSiteSourced())->toBeTrue()
        ->and($pool->originSiteId())->toBe((string) $site->id)
        ->and($pool->shouldStopOnBoxWorkers())->toBeTrue()
        ->and($pool->source_server_id)->toBe($app->id)
        ->and($pool->desired_count)->toBe(1);

    $worker = $pool->primaryServer;
    expect($worker)->toBeInstanceOf(Server::class)
        ->and($worker->status)->toBe(Server::STATUS_PENDING)
        ->and($worker->pool_role)->toBe(WorkerPool::ROLE_PRIMARY)
        ->and($worker->provider)->toBe(ServerProvider::DigitalOcean)
        ->and($worker->private_network_id)->toBe($network->id)
        ->and($worker->size)->toBe('s-2vcpu-2gb')
        ->and($worker->meta['server_role'] ?? null)->toBe('worker')
        ->and($worker->meta['install_profile'] ?? null)->toBe('queue_worker')
        ->and($worker->name)->toStartWith('dply-app-worker-');

    expect($site->fresh()->attachedWorkerPools()->contains('id', $pool->id))->toBeTrue();

    Queue::assertPushed(ProvisionDigitalOceanDropletJob::class);
    Queue::assertPushed(ReconcileWorkerPoolJob::class, fn (ReconcileWorkerPoolJob $job): bool => $job->poolId === $pool->id);
});

it('pins the worker php version to the origin site, not the app server default', function () {
    Queue::fake();
    [$user, , , $app, , $site] = fleetReadySite([
        'app' => [
            'meta' => [
                'server_role' => 'application',
                'host_kind' => 'vm',
                'php_version' => '8.3',
                'default_php_version' => '8.3',
            ],
        ],
    ]);
    $site->forceFill(['runtime' => 'php', 'runtime_version' => '8.4'])->save();

    $pool = app(WorkerPoolManager::class)->createPoolFromSite($user, $site, 's-2vcpu-2gb', true);
    $worker = $pool->primaryServer;

    expect($worker)->toBeInstanceOf(Server::class)
        ->and($worker->meta['php_version'] ?? null)->toBe('8.4')
        ->and($worker->meta['default_php_version'] ?? null)->toBe('8.4');
});

it('provisions a managed-backend worker in another region without joining the site VPC', function () {
    Queue::fake();
    [$user, $org, $network, $app, , $site] = fleetReadySite();
    $site->forceFill(['env_file_content' => "APP_ENV=production\n"])->save();
    $database = CloudDatabase::factory()->active()->create([
        'organization_id' => $org->id,
        'name' => 'app-pg',
    ]);
    $redis = CloudDatabase::factory()->active()->redis()->create([
        'organization_id' => $org->id,
        'name' => 'app-valkey',
    ]);
    SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'database',
        'mode' => 'managed',
        'status' => SiteBinding::STATUS_CONFIGURED,
        'name' => 'primary',
        'target_type' => 'cloud_database',
        'target_id' => $database->id,
        'injected_env' => [],
    ]);
    SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'redis',
        'mode' => 'managed',
        'status' => SiteBinding::STATUS_CONFIGURED,
        'name' => 'primary',
        'target_type' => 'cloud_database',
        'target_id' => $redis->id,
        'injected_env' => [],
    ]);

    $pool = app(WorkerPoolManager::class)->createPoolFromSite($user, $site->fresh(), 's-2vcpu-2gb', true, 'sfo3');
    $worker = $pool->primaryServer;

    expect($worker)->toBeInstanceOf(Server::class)
        ->and($worker->region)->toBe('sfo3')
        ->and($worker->private_network_id)->toBeNull()
        ->and($worker->hetzner_network_id)->toBeNull()
        ->and($worker->meta['cross_region'] ?? null)->toBeTrue()
        ->and(data_get($worker->meta, 'placement.region'))->toBe('sfo3')
        ->and(data_get($worker->meta, 'placement.source_region'))->toBe((string) $app->region);

    expect($app->fresh()->private_network_id)->toBe($network->id);
});

it('keeps a VM-backend worker in the site region even if another region is requested', function () {
    Queue::fake();
    [$user, , $network, $app, , $site] = fleetReadySite();

    $pool = app(WorkerPoolManager::class)->createPoolFromSite($user, $site, 's-2vcpu-2gb', true, 'sfo3');
    $worker = $pool->primaryServer;

    expect($worker)->toBeInstanceOf(Server::class)
        ->and($worker->region)->toBe((string) $app->region)
        ->and($worker->private_network_id)->toBe($network->id)
        ->and($worker->meta['cross_region'] ?? null)->toBeNull();
});

it('refuses a second site-sourced fleet on the same site', function () {
    Queue::fake();
    [$user, , , , , $site] = fleetReadySite();
    $manager = app(WorkerPoolManager::class);
    $manager->createPoolFromSite($user, $site, 's-1vcpu-1gb', false);

    expect(fn () => $manager->createPoolFromSite($user, $site->fresh(), 's-1vcpu-1gb', false))
        ->toThrow(RuntimeException::class, 'already has workers');
});

it('replicates the origin site as a hidden fleet replica', function () {
    Queue::fake();
    [, , , , , $site] = fleetReadySite();
    $worker = Server::factory()->digitalOcean()->create([
        'organization_id' => $site->organization_id,
        'user_id' => $site->user_id,
        'name' => 'dply-app-worker-1',
        'status' => Server::STATUS_READY,
        'meta' => ['server_role' => 'worker'],
    ]);

    app(WorkerWorkloadReplayer::class)->replicateOriginSite($site, $worker);

    $replica = Site::query()->where('server_id', $worker->id)->first();
    expect($replica)->toBeInstanceOf(Site::class)
        ->and($replica->isFleetReplica())->toBeTrue()
        ->and($replica->meta['fleet_replica_of_site_id'] ?? null)->toBe((string) $site->id)
        ->and($replica->meta['fleet_hidden'] ?? null)->toBeTrue()
        ->and($replica->laravel_scheduler)->toBeFalse()
        ->and((string) $replica->env_file_content)->toContain('DPLY_WORKER_ROLE=replica');

    expect(Site::query()->visibleInSiteIndex()->pluck('id'))
        ->toContain($site->id)
        ->not->toContain($replica->id);

    expect($site->fleetReplicaSites()->pluck('id'))->toContain($replica->id);

    Queue::assertPushed(ProvisionSiteJob::class);
});

it('pauses and restores on-box queue daemons but leaves the scheduler', function () {
    Queue::fake();
    [, , , $app, , $site] = fleetReadySite();

    $horizon = $site->processes()->create([
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'horizon',
        'command' => 'php artisan horizon',
        'scale' => 1,
        'is_active' => true,
    ]);
    $scheduler = $site->processes()->create([
        'type' => SiteProcess::TYPE_SCHEDULER,
        'name' => 'scheduler',
        'command' => 'php artisan schedule:work',
        'scale' => 1,
        'is_active' => true,
    ]);
    $sv = SupervisorProgram::query()->create([
        'server_id' => $app->id,
        'site_id' => $site->id,
        'slug' => 'horizon',
        'program_type' => 'horizon',
        'command' => 'php artisan horizon',
        'directory' => '/var/www/app',
        'numprocs' => 1,
        'is_active' => true,
    ]);

    $paused = app(SiteWorkerFleetOnBoxDaemons::class)->pause($site);

    expect($paused)->toBe(2)
        ->and($horizon->fresh()->is_active)->toBeFalse()
        ->and($sv->fresh()->is_active)->toBeFalse()
        ->and($scheduler->fresh()->is_active)->toBeTrue()
        ->and(data_get($site->fresh()->meta, 'fleet_paused_onbox.processes'))->toContain((string) $horizon->id);

    Queue::assertPushed(ProvisionSiteSystemdUnitsJob::class);

    $restored = app(SiteWorkerFleetOnBoxDaemons::class)->restore($site->fresh());

    expect($restored)->toBe(2)
        ->and($horizon->fresh()->is_active)->toBeTrue()
        ->and($sv->fresh()->is_active)->toBeTrue()
        ->and(data_get($site->fresh()->meta, 'fleet_paused_onbox'))->toBeNull();
});

it('dissolves a site-sourced fleet, drains every member, and restores on-box workers', function () {
    Queue::fake();
    [$user, , , , , $site] = fleetReadySite();
    $manager = app(WorkerPoolManager::class);
    $pool = $manager->createPoolFromSite($user, $site, 's-1vcpu-1gb', true);

    $horizon = $site->processes()->create([
        'type' => SiteProcess::TYPE_WORKER,
        'name' => 'horizon',
        'command' => 'php artisan horizon',
        'scale' => 1,
        'is_active' => true,
    ]);
    app(SiteWorkerFleetOnBoxDaemons::class)->pause($site);

    $drained = $manager->dissolveSiteSourcedPool($pool->fresh(), true, $user);

    expect($drained)->toBe(1)
        ->and(WorkerPool::query()->find($pool->id))->toBeNull()
        ->and($horizon->fresh()->is_active)->toBeTrue()
        ->and(data_get($site->fresh()->meta, 'fleet_paused_onbox'))->toBeNull();

    Queue::assertPushed(DrainAndDestroyWorkerJob::class);
});

it('will not scale a pool to zero', function () {
    Queue::fake();
    [$user, , , , , $site] = fleetReadySite();
    $manager = app(WorkerPoolManager::class);
    $pool = $manager->createPoolFromSite($user, $site, 's-1vcpu-1gb', false);

    $manager->setDesiredCount($pool, 0);

    expect($pool->fresh()->desired_count)->toBe(1);
});

it('pushes env changes from the origin site to hidden fleet replicas', function () {
    Queue::fake();
    [, , , , , $site] = fleetReadySite();
    $worker = Server::factory()->digitalOcean()->create([
        'organization_id' => $site->organization_id,
        'user_id' => $site->user_id,
        'name' => 'dply-app-worker-1',
        'status' => Server::STATUS_READY,
    ]);
    $replica = Site::factory()->create([
        'server_id' => $worker->id,
        'user_id' => $site->user_id,
        'organization_id' => $site->organization_id,
        'name' => 'App',
        'env_file_content' => "REDIS_HOST=10.10.0.20\nDPLY_WORKER_ROLE=replica\n",
        'meta' => [
            'fleet_replica_of_site_id' => (string) $site->id,
            'fleet_hidden' => true,
        ],
    ]);

    $site->forceFill([
        'env_file_content' => "REDIS_HOST=10.10.0.20\nAPP_KEY=base64:new\nDPLY_WORKER_ROLE=primary\n",
    ])->save();

    expect((string) $replica->fresh()->env_file_content)
        ->toContain('APP_KEY=base64:new')
        ->toContain('DPLY_WORKER_ROLE=replica')
        ->not->toContain('DPLY_WORKER_ROLE=primary');

    Queue::assertPushed(PushSiteEnvJob::class, fn (PushSiteEnvJob $job): bool => $job->siteId === (string) $replica->id);
});

it('opens the worker process log from the fleet page', function () {
    Queue::fake();
    [$user, , , $app, , $site] = fleetReadySite();
    $pool = app(WorkerPoolManager::class)->createPoolFromSite($user, $site, 's-1vcpu-1gb', false);
    $worker = $pool->primaryServer;
    expect($worker)->toBeInstanceOf(Server::class);

    ConsoleAction::query()->create([
        'subject_type' => $worker->getMorphClass(),
        'subject_id' => $worker->id,
        'kind' => 'worker_pool_scale',
        'status' => ConsoleAction::STATUS_RUNNING,
        'label' => 'Scaling the worker pool',
        'started_at' => now(),
        'output' => [
            'v' => 1,
            'lines' => [[
                't' => now()->getTimestampMs(),
                'level' => 'step',
                'source' => 'provision',
                'line' => 'provisioning replica '.$worker->name,
            ]],
        ],
    ]);

    session(['current_organization_id' => $site->organization_id]);

    Livewire::actingAs($user)
        ->test(SiteSettings::class, [
            'server' => $app,
            'site' => $site->fresh(),
            'section' => 'worker-fleet',
        ])
        ->assertSee('View install')
        ->assertSee('Queued with DigitalOcean')
        ->assertSee('1 of 7')
        ->assertSee('provisioning replica '.$worker->name)
        ->call('openWorkerProcessModal', (string) $worker->id)
        ->assertSet('showWorkerProcessModal', true)
        ->assertSee('Open full install')
        ->assertSee('Provision path')
        ->assertSee('Request queued with provider')
        ->assertSee('Provisioning server')
        ->assertSee('Site release')
        ->assertDontSee('Fleet progress');
});

it('manages a site-sourced fleet on the origin site, not the worker pool page', function () {
    Queue::fake();
    [$user, , , $app, , $site] = fleetReadySite();
    $pool = app(WorkerPoolManager::class)->createPoolFromSite($user, $site, 's-1vcpu-1gb', false);
    $worker = $pool->primaryServer;
    expect($worker)->toBeInstanceOf(Server::class);

    $fleetUrl = route('sites.show', [
        'server' => $app,
        'site' => $site,
        'section' => 'worker-fleet',
    ]);

    expect($pool->workspaceUrl())->toBe($fleetUrl);

    session(['current_organization_id' => $site->organization_id]);

    Livewire::actingAs($user)
        ->test(WorkspaceWorkerPool::class, ['server' => $worker])
        ->assertRedirect($fleetUrl);
});

it('presents a site-sourced worker as a worker server, not a pending site', function () {
    Queue::fake();
    [$user, , , $app, , $site] = fleetReadySite();
    $pool = app(WorkerPoolManager::class)->createPoolFromSite($user, $site, 's-1vcpu-1gb', false);
    $worker = $pool->primaryServer;
    expect($worker)->toBeInstanceOf(Server::class);

    $worker->forceFill([
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
    ])->save();

    app(WorkerWorkloadReplayer::class)->replicateOriginSite($site, $worker);

    session(['current_organization_id' => $site->organization_id]);

    Livewire::actingAs($user)
        ->test(WorkspaceOverview::class, ['server' => $worker->fresh()])
        ->assertSee('Worker server')
        ->assertSee('Queue workers for '.$site->name)
        ->assertSee('Workload')
        ->assertSee('Installing')
        ->assertSee('Worker Servers')
        ->assertDontSee('Pending')
        ->assertDontSee('Add your first site')
        ->assertDontSee('Open Sites');

    Livewire::actingAs($user)
        ->test(WorkspaceSites::class, ['server' => $worker->fresh()])
        ->assertSee('Worker server')
        ->assertSee('Workload')
        ->assertSee('Queue workload')
        ->assertSee('Installing')
        ->assertSee('Worker')
        ->assertSee('Worker Servers')
        ->assertDontSee('Pending')
        ->assertDontSee('Add site')
        ->assertDontSee('Sync ')
        ->assertDontSee('Deploy PHP/Laravel apps from Git');
});

it('stops waiting when a worker’s cloud provision already failed', function () {
    Queue::fake();
    [$user, , , , , $site] = fleetReadySite();
    $pool = app(WorkerPoolManager::class)->createPoolFromSite($user, $site, 's-1vcpu-1gb', false);
    $worker = $pool->primaryServer;
    expect($worker)->toBeInstanceOf(Server::class);

    $meta = is_array($worker->meta) ? $worker->meta : [];
    $meta['provision_error'] = [
        'provider' => 'digitalocean',
        'message' => 'DigitalOcean API failed to create SSH key: Unable to authenticate you',
    ];
    $meta['pool']['state'] = WorkerPool::MEMBER_PROVISIONING;
    $worker->forceFill([
        'status' => Server::STATUS_ERROR,
        'provider_id' => null,
        'meta' => $meta,
    ])->save();

    Queue::fake();

    (new ReconcileWorkerPoolJob((string) $pool->id))->handle(
        app(WorkerPoolManager::class),
        app(WorkerWorkloadReplayer::class),
    );

    expect($worker->fresh()->poolMemberState())->toBe(WorkerPool::MEMBER_ERRORED)
        ->and($pool->fresh()->status)->toBe(WorkerPool::STATUS_DEGRADED);

    Queue::assertNotPushed(ReconcileWorkerPoolJob::class);
});

it('retries a failed worker cloud provision', function () {
    Queue::fake();
    [$user, , , , , $site] = fleetReadySite();
    $pool = app(WorkerPoolManager::class)->createPoolFromSite($user, $site, 's-1vcpu-1gb', false);
    $worker = $pool->primaryServer;
    expect($worker)->toBeInstanceOf(Server::class);

    $meta = is_array($worker->meta) ? $worker->meta : [];
    $meta['provision_error'] = [
        'provider' => 'digitalocean',
        'message' => 'DigitalOcean API failed to create SSH key: Unable to authenticate you',
    ];
    $worker->forceFill([
        'status' => Server::STATUS_ERROR,
        'provider_id' => null,
        'meta' => $meta,
    ])->save();

    Queue::fake();
    app(WorkerCloneProvisioner::class)->retryCloudProvision($worker->fresh());

    expect($worker->fresh()->status)->toBe(Server::STATUS_PENDING)
        ->and($worker->fresh()->meta['provision_error'] ?? null)->toBeNull();

    Queue::assertPushed(ProvisionDigitalOceanDropletJob::class);
    Queue::assertPushed(ReconcileWorkerPoolJob::class);
});

it('uses the newest DigitalOcean credential when adding or retrying a worker', function () {
    Queue::fake();
    [$user, $org, , $app, , $site, $old] = fleetReadySite();

    $fresh = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'digitalocean',
        'name' => 'aug_19',
        'created_at' => now()->addMinute(),
    ]);

    expect((string) $app->provider_credential_id)->toBe((string) $old->id);

    $pool = app(WorkerPoolManager::class)->createPoolFromSite($user, $site, 's-1vcpu-1gb', false);
    $worker = $pool->primaryServer;
    expect($worker)->toBeInstanceOf(Server::class)
        ->and((string) $worker->provider_credential_id)->toBe((string) $fresh->id);

    $worker->forceFill([
        'status' => Server::STATUS_ERROR,
        'provider_id' => null,
        'provider_credential_id' => $old->id,
        'meta' => array_merge(is_array($worker->meta) ? $worker->meta : [], [
            'provision_error' => ['message' => 'Unable to authenticate you'],
        ]),
    ])->save();

    app(WorkerCloneProvisioner::class)->retryCloudProvision($worker->fresh());

    expect((string) $worker->fresh()->provider_credential_id)->toBe((string) $fresh->id);
});
