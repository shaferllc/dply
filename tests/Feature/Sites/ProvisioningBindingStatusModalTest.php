<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\ProvisioningBindingStatusModalTest;

use App\Enums\ServerProvider;
use App\Livewire\Sites\ResourceMap;
use App\Livewire\Sites\SiteSetup;
use App\Models\CloudDatabase;
use App\Models\ConsoleAction;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\User;
use App\Modules\Cloud\Jobs\TeardownCloudDatabaseJob;
use App\Modules\Database\Backends\DatabaseRouter;
use App\Modules\Database\Jobs\ProvisionDedicatedRedisVmJob;
use App\Modules\Database\Jobs\ProvisionManagedDatabaseJob;
use App\Modules\Database\Jobs\ResizeManagedDatabaseJob;
use App\Support\Sites\ManagedDatabaseProvisionConsole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function provisioningMapSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $appServer = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ip_address' => '203.0.113.10',
        'ssh_private_key' => 'fake-key',
    ]);
    $site = Site::factory()->create([
        'server_id' => $appServer->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    return [$user, $appServer, $site];
}

test('provisioning redis card is clickable and opens live status', function () {
    [$user, $appServer, $site] = provisioningMapSite();

    $cacheServer = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $appServer->organization_id,
        'name' => 'acme-redis',
        'status' => Server::STATUS_PROVISIONING,
        'setup_status' => Server::SETUP_STATUS_PENDING,
        'ip_address' => '',
        'meta' => [
            'server_role' => 'redis',
            'install_profile' => 'redis_server',
        ],
    ]);

    $binding = SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'redis',
        'mode' => 'provision_new',
        'status' => SiteBinding::STATUS_PROVISIONING,
        'name' => 'primary',
        'injected_env' => [],
        'config' => [
            'engine' => 'redis',
            'placement' => 'cache_vm',
            'cache_vm_server_id' => (string) $cacheServer->id,
        ],
    ]);

    Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $appServer, 'site' => $site])
        ->assertSee(__('View status'))
        ->assertSee(__('Cloud provisioning'))
        ->assertSee(__('Provisioning server'))
        ->call('openBindingInfoModal', (string) $binding->id)
        ->assertDispatched('open-modal', 'binding-info-modal')
        ->assertSet('bindingInfo.status', SiteBinding::STATUS_PROVISIONING)
        ->assertSet('bindingInfo.provision.active', true)
        ->assertSet('bindingInfo.provision.server_name', 'acme-redis')
        ->assertSet('bindingInfo.provision.digest_phase', __('Cloud provisioning'))
        ->assertSee(__('Provisioning status'))
        ->assertSee(__('Dedicated Redis server'))
        ->assertSee(__('Open journey'));
});

test('errored redis card surfaces the failure and retries provision', function () {
    Queue::fake();
    [$user, $appServer, $site] = provisioningMapSite();

    $cacheServer = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $appServer->organization_id,
        'name' => 'acme-redis',
        'status' => Server::STATUS_ERROR,
        'setup_status' => Server::SETUP_STATUS_FAILED,
        'ip_address' => '203.0.113.88',
        'ssh_private_key' => 'fake-key',
        'meta' => [
            'server_role' => 'redis',
            'install_profile' => 'redis_server',
            'provision_error' => [
                'message' => 'size is not available in this region',
            ],
        ],
    ]);

    $binding = SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'redis',
        'mode' => 'provision_new',
        'status' => SiteBinding::STATUS_ERROR,
        'name' => 'primary',
        'target_type' => 'server_cache_service',
        'target_id' => '01cache-service',
        'injected_env' => [],
        'config' => [
            'engine' => 'redis',
            'placement' => 'cache_vm',
            'cache_vm_server_id' => (string) $cacheServer->id,
        ],
        'last_error' => 'The Redis server failed to provision.',
    ]);

    Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $appServer, 'site' => $site])
        ->assertSee(__('View error'))
        ->assertSee('The Redis server failed to provision.')
        ->assertSee('size is not available in this region')
        ->assertSee(__('Retry'))
        ->call('openBindingInfoModal', (string) $binding->id)
        ->assertSet('bindingInfo.provision.failed', true)
        ->assertSet('bindingInfo.provision.can_retry', true)
        ->assertSee(__('Provision failed'))
        ->assertSee(__('Retry provision'))
        ->call('retryFailedBindingProvision', (string) $binding->id)
        ->assertSet('bindingInfo.status', SiteBinding::STATUS_PROVISIONING);

    expect($binding->fresh()->status)->toBe(SiteBinding::STATUS_PROVISIONING)
        ->and($binding->fresh()->last_error)->toBeNull();

    Queue::assertPushed(ProvisionDedicatedRedisVmJob::class, function (ProvisionDedicatedRedisVmJob $job) use ($cacheServer, $site, $binding): bool {
        return $job->serverId === (string) $cacheServer->id
            && $job->siteId === (string) $site->id
            && $job->siteBindingId === (string) $binding->id;
    });
});

/**
 * @return array{0: User, 1: Server, 2: Site, 3: CloudDatabase, 4: SiteBinding}
 */
function fakeDoManagedDatabaseOptions(array $redisRegions = [
    'ams3', 'atl1', 'blr1', 'fra1', 'lon1', 'mkc1', 'nyc1', 'nyc2', 'nyc3', 'ric1', 'sfo2', 'sfo3', 'sgp1', 'syd1', 'tor1',
], array $valkeyRegions = [
    'ams3', 'atl1', 'blr1', 'fra1', 'lon1', 'nyc1', 'nyc3', 'ric1', 'sfo2', 'sfo3', 'sgp1', 'syd1', 'tor1',
]): void
{
    $layouts = [
        ['num_nodes' => 1, 'sizes' => [
            'db-s-1vcpu-1gb', 'db-s-1vcpu-2gb', 'db-s-2vcpu-4gb', 'db-s-4vcpu-8gb', 'db-s-6vcpu-16gb',
            'm-2vcpu-16gb', 'm-4vcpu-32gb',
        ]],
    ];
    Http::fake([
        'https://api.digitalocean.com/v2/databases/options*' => Http::response([
            'options' => [
                'redis' => ['regions' => $redisRegions, 'versions' => ['7'], 'layouts' => $layouts],
                'valkey' => [
                    'regions' => $valkeyRegions,
                    'versions' => ['8'],
                    'default_version' => '8',
                    'layouts' => $layouts,
                ],
                'pg' => ['regions' => $redisRegions, 'versions' => ['16', '15'], 'default_version' => '16'],
                'mysql' => ['regions' => $redisRegions, 'versions' => ['8'], 'default_version' => '8'],
            ],
        ], 200),
    ]);
}

function failedManagedRedisPrimary(): array
{
    fakeDoManagedDatabaseOptions();
    [$user, $appServer, $site] = provisioningMapSite();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $appServer->organization_id,
        'provider' => 'digitalocean',
    ]);
    $appServer->forceFill([
        'provider' => ServerProvider::DigitalOcean,
        'provider_credential_id' => $credential->id,
        'region' => 'sfo2',
    ])->save();

    $cluster = CloudDatabase::factory()->redis()->create([
        'organization_id' => $appServer->organization_id,
        'name' => 'dply_io_redis',
        'region' => 'sfo3',
        'status' => CloudDatabase::STATUS_FAILED,
        'provider_credential_id' => $credential->id,
        'meta' => ['error' => "DigitalOcean API failed to create database cluster: region 'sfo3' is not valid"],
    ]);

    $binding = SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'redis',
        'mode' => 'provision_new',
        'status' => SiteBinding::STATUS_ERROR,
        'name' => 'primary',
        'target_type' => 'cloud_database',
        'target_id' => (string) $cluster->id,
        'injected_env' => [],
        'config' => [
            'engine' => CloudDatabase::ENGINE_REDIS,
            'connection' => '',
            'service' => 'dply_io_redis · managed',
            'managed' => true,
            'placement' => 'managed',
            'region' => 'sfo3',
            'size' => 'small',
        ],
        'last_error' => "DigitalOcean API failed to create database cluster: region 'sfo3' is not valid",
    ]);

    return [$user, $appServer, $site, $cluster, $binding];
}

test('errored managed redis primary remakes the same binding instead of blocking on itself', function () {
    Queue::fake();
    [$user, $appServer, $site, $cluster, $binding] = failedManagedRedisPrimary();

    Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $appServer, 'site' => $site])
        ->assertSee(__('Retry'))
        ->assertDontSee(__('This site already has a primary'))
        ->call('openBindingInfoModal', (string) $binding->id)
        ->assertSet('bindingInfo.provision.failed', true)
        ->assertSet('bindingInfo.provision.can_retry', true)
        ->call('retryFailedBindingProvision', (string) $binding->id)
        ->assertHasNoErrors()
        ->assertSet('bindingInfo.status', SiteBinding::STATUS_PROVISIONING);

    $fresh = $binding->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->id)->toBe($binding->id)
        ->and($fresh->status)->toBe(SiteBinding::STATUS_PROVISIONING)
        ->and($fresh->last_error)->toBeNull()
        ->and($fresh->name)->toBe('primary');

    expect(CloudDatabase::query()->whereKey($cluster->id)->exists())->toBeFalse();

    $remade = CloudDatabase::query()->whereKey($fresh->target_id)->first();
    expect($remade)->not->toBeNull()
        ->and($remade->engine)->toBe(CloudDatabase::ENGINE_REDIS)
        ->and($remade->region)->toBe('sfo2')
        ->and($remade->size)->toBe('db-s-1vcpu-1gb')
        ->and($remade->status)->toBe(CloudDatabase::STATUS_PROVISIONING);

    Queue::assertPushed(ProvisionManagedDatabaseJob::class, function (ProvisionManagedDatabaseJob $job) use ($fresh, $appServer): bool {
        return $job->siteBindingId === (string) $fresh->id
            && $job->cloudDatabaseId === (string) $fresh->target_id
            && $job->serverId === (string) $appServer->id;
    });
});

test('errored managed redis can pick a new region and remake', function () {
    Queue::fake();
    [$user, $appServer, $site, $cluster, $binding] = failedManagedRedisPrimary();

    $component = Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $appServer, 'site' => $site])
        ->assertSee(__('Change placement'))
        ->call('openBindingInfoModal', (string) $binding->id)
        ->assertSee(__('Choose a region'))
        ->assertSee('nyc3');

    $values = collect($component->get('bindingInfo.provision.regions'))->pluck('value')->all();
    expect($values)->toContain('nyc3', 'ams3', 'sfo2', 'atl1', 'ric1')
        ->and($values)->not->toContain('sfo3', 'nyc2', 'mkc1');

    $component
        ->call('openFailedBindingRepair', (string) $binding->id)
        ->assertDispatched('close-modal', 'binding-info-modal')
        ->assertDispatched('open-modal', 'site-binding-modal')
        ->assertSet('bindingModalMode', 'provision')
        ->assertSet('bindingModalBindingId', (string) $binding->id)
        ->assertSet('bindingForm.placement', 'managed')
        ->assertSet('bindingForm.name', 'dply_io_redis')
        ->assertSet('bindingForm.region', 'sfo2')
        ->assertSee('db-s-4vcpu-8gb')
        ->assertSee('m-2vcpu-16gb')
        ->assertSee('San Francisco · sfo2')
        ->assertSee('Atlanta · atl1')
        ->set('bindingForm.region', 'nyc3')
        ->call('saveBinding')
        ->assertHasNoErrors();

    $fresh = $binding->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->id)->toBe($binding->id)
        ->and($fresh->status)->toBe(SiteBinding::STATUS_PROVISIONING)
        ->and($fresh->last_error)->toBeNull();

    expect(CloudDatabase::query()->whereKey($cluster->id)->exists())->toBeFalse();

    $remade = CloudDatabase::query()->whereKey($fresh->target_id)->first();
    expect($remade)->not->toBeNull()
        ->and($remade->region)->toBe('nyc3');
});

/**
 * @return array{0: User, 1: Server, 2: Site, 3: CloudDatabase, 4: SiteBinding}
 */
function provisioningManagedValkeyPrimary(): array
{
    [$user, $appServer, $site] = provisioningMapSite();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $appServer->organization_id,
        'provider' => 'digitalocean',
    ]);
    $appServer->forceFill([
        'provider' => ServerProvider::DigitalOcean,
        'provider_credential_id' => $credential->id,
        'region' => 'sfo3',
    ])->save();

    $cluster = CloudDatabase::factory()->redis()->create([
        'organization_id' => $appServer->organization_id,
        'name' => 'dply_io_redis',
        'region' => 'sfo3',
        'size' => 'db-s-1vcpu-2gb',
        'status' => CloudDatabase::STATUS_PROVISIONING,
        'backend_id' => 'do-valkey-test',
        'provider_credential_id' => $credential->id,
    ]);

    $binding = SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'redis',
        'mode' => 'provision_new',
        'status' => SiteBinding::STATUS_PROVISIONING,
        'name' => 'primary',
        'target_type' => 'cloud_database',
        'target_id' => (string) $cluster->id,
        'injected_env' => [],
        'config' => [
            'engine' => CloudDatabase::ENGINE_REDIS,
            'managed' => true,
            'placement' => 'managed',
            'region' => 'sfo3',
            'size' => 'db-s-1vcpu-2gb',
        ],
    ]);

    return [$user, $appServer, $site, $cluster, $binding];
}

/**
 * @return array{0: User, 1: Server, 2: Site, 3: CloudDatabase, 4: SiteBinding}
 */
function provisioningManagedDatabasePrimary(): array
{
    [$user, $appServer, $site] = provisioningMapSite();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $appServer->organization_id,
        'provider' => 'digitalocean',
    ]);
    $appServer->forceFill([
        'provider' => ServerProvider::DigitalOcean,
        'provider_credential_id' => $credential->id,
        'region' => 'sfo3',
    ])->save();

    $cluster = CloudDatabase::factory()->create([
        'organization_id' => $appServer->organization_id,
        'name' => 'dply_io',
        'engine' => CloudDatabase::ENGINE_POSTGRES,
        'region' => 'sfo3',
        'size' => 'db-s-1vcpu-1gb',
        'status' => CloudDatabase::STATUS_PROVISIONING,
        'backend_id' => 'do-pg-test',
        'provider_credential_id' => $credential->id,
    ]);

    $binding = SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'database',
        'mode' => 'provision_new',
        'status' => SiteBinding::STATUS_PROVISIONING,
        'name' => 'primary',
        'target_type' => 'cloud_database',
        'target_id' => (string) $cluster->id,
        'injected_env' => [],
        'config' => [
            'engine' => CloudDatabase::ENGINE_POSTGRES,
            'managed' => true,
            'placement' => 'managed',
            'region' => 'sfo3',
            'size' => 'db-s-1vcpu-1gb',
        ],
    ]);

    return [$user, $appServer, $site, $cluster, $binding];
}

function fakeDoManagedClusterStatus(string $id = 'do-valkey-test', string $status = 'creating'): void
{
    $layouts = [
        ['num_nodes' => 1, 'sizes' => [
            'db-s-1vcpu-1gb', 'db-s-1vcpu-2gb', 'db-s-2vcpu-4gb', 'db-s-4vcpu-8gb',
        ]],
    ];

    Http::fake([
        'https://api.digitalocean.com/v2/databases/options*' => Http::response([
            'options' => [
                'valkey' => [
                    'regions' => ['sfo3', 'nyc3'],
                    'versions' => ['8'],
                    'default_version' => '8',
                    'layouts' => $layouts,
                ],
            ],
        ], 200),
        'https://api.digitalocean.com/v2/databases/'.$id => Http::response([
            'database' => [
                'id' => $id,
                'status' => $status,
                'engine' => 'valkey',
                'connection' => [
                    'host' => '',
                    'port' => 0,
                    'user' => '',
                    'password' => '',
                    'database' => '',
                    'ssl' => true,
                ],
            ],
        ], 200),
    ]);
}

test('provisioning managed redis modal streams cluster console output', function () {
    fakeDoManagedClusterStatus();
    [$user, $appServer, $site, $cluster, $binding] = provisioningManagedValkeyPrimary();

    $component = Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $appServer, 'site' => $site])
        ->call('openBindingInfoModal', (string) $binding->id)
        ->assertSet('bindingInfo.status', SiteBinding::STATUS_PROVISIONING)
        ->assertSee(__('Provisioning status'))
        ->assertSee(__('Provisioning managed Valkey'));

    $runId = $component->get('bindingInfo.provision.console_run_id');
    expect($runId)->not->toBeEmpty();

    $run = ConsoleAction::query()->find($runId);
    expect($run)->not->toBeNull()
        ->and($run->kind)->toBe(ManagedDatabaseProvisionConsole::KIND)
        ->and($run->isInFlight())->toBeTrue();

    $output = collect($run->lines())->pluck('line')->implode("\n");
    expect($output)->toContain('status=creating')
        ->and($output)->toContain($cluster->region)
        ->and($binding->fresh()->config['console_run_id'] ?? null)->toBe($runId);
});

test('stopping an in-flight managed provision ends the banner and binding', function () {
    Queue::fake();
    fakeDoManagedClusterStatus();
    [$user, $appServer, $site, $cluster, $binding] = provisioningManagedValkeyPrimary();

    $component = Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $appServer, 'site' => $site])
        ->call('openBindingInfoModal', (string) $binding->id)
        ->assertSee(__('Stop'));

    $runId = $component->get('bindingInfo.provision.console_run_id');
    expect($runId)->not->toBeEmpty();

    $component->call('cancelConsoleActionRun', (string) $runId)
        ->assertSet('bindingInfo.status', SiteBinding::STATUS_ERROR);

    $run = ConsoleAction::query()->find($runId);
    expect($run)->not->toBeNull()
        ->and($run->isInFlight())->toBeFalse()
        ->and($run->status)->toBe(ConsoleAction::STATUS_FAILED)
        ->and($run->error)->toBe(__('Stopped.'))
        ->and($binding->fresh()->status)->toBe(SiteBinding::STATUS_ERROR)
        ->and($cluster->fresh()->status)->toBe(CloudDatabase::STATUS_FAILED);
});

test('managed provision job does not keep creating after the binding is stopped', function () {
    Http::fake();
    [$user, $appServer, $site, $cluster, $binding] = provisioningManagedValkeyPrimary();

    $binding->forceFill([
        'status' => SiteBinding::STATUS_ERROR,
        'last_error' => 'Stopped.',
    ])->save();

    (new ProvisionManagedDatabaseJob(
        (string) $cluster->id,
        (string) $binding->id,
        (string) $appServer->id,
    ))->handle(app(DatabaseRouter::class));

    Http::assertNothingSent();
    expect($cluster->fresh()->status)->toBe(CloudDatabase::STATUS_PROVISIONING)
        ->and($binding->fresh()->status)->toBe(SiteBinding::STATUS_ERROR);
});

test('provider auth failure forces a new token instead of retry', function () {
    Queue::fake();
    [$user, $appServer, $site, $cluster, $binding] = failedManagedRedisPrimary();

    $error = 'DigitalOcean API failed to create database cluster: Unable to authenticate you (sent engine=mysql version= region=sfo2 size=db-s-1vcpu-2gb)';
    $binding->forceFill(['last_error' => $error])->save();
    $cluster->forceFill(['meta' => ['error' => $error]])->save();

    $component = Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $appServer, 'site' => $site])
        ->assertSee(__('Reconnect'))
        ->assertDontSee(__('Retry'), false)
        ->call('openBindingInfoModal', (string) $binding->id)
        ->assertSet('bindingInfo.provision.auth_failure', true)
        ->assertSet('bindingInfo.provision.can_retry', false)
        ->assertSet('bindingInfo.provision.auth_provider', 'digitalocean')
        ->assertSee(__('Token rejected'))
        ->assertSee(__('Add a new token'))
        ->assertDontSee(__('Retry provision'))
        ->assertDispatched('open-add-provider-credential-modal');

    $component->call('afterProviderCredentialCreatedForBindings', 'digitalocean')
        ->assertSet('bindingInfo.status', SiteBinding::STATUS_PROVISIONING);

    expect($binding->fresh()->status)->toBe(SiteBinding::STATUS_PROVISIONING);

    Queue::assertPushed(ProvisionManagedDatabaseJob::class);
});

test('failed managed provision modal does not embed the console error again', function () {
    [$user, $appServer, $site, $cluster, $binding] = failedManagedRedisPrimary();

    $error = $binding->last_error;
    expect($error)->not->toBeEmpty();

    $run = ConsoleAction::query()->create([
        'subject_type' => $site->getMorphClass(),
        'subject_id' => $site->id,
        'kind' => ManagedDatabaseProvisionConsole::KIND,
        'status' => ConsoleAction::STATUS_FAILED,
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'label' => ManagedDatabaseProvisionConsole::label($cluster),
        'error' => $error,
        'output' => [
            'v' => 1,
            'lines' => [[
                't' => now()->getTimestampMs(),
                'level' => ConsoleAction::LEVEL_ERROR,
                'source' => 'digitalocean',
                'line' => $error,
            ]],
        ],
    ]);

    $config = is_array($binding->config) ? $binding->config : [];
    $config['console_run_id'] = (string) $run->id;
    $binding->forceFill(['config' => $config])->save();

    $html = Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $appServer, 'site' => $site])
        ->call('openBindingInfoModal', (string) $binding->id)
        ->assertSet('bindingInfo.provision.failed', true)
        ->assertSet('bindingInfo.provision.console_run_id', (string) $run->id)
        ->assertSee(__('Provision failed'))
        ->assertSee($error)
        ->html();

    expect(substr_count($html, 'wire:key="console-action-banner-static"'))->toBe(1);
});

test('managed provision job writes console poll lines and keeps the same run', function () {
    Queue::fake();
    fakeDoManagedClusterStatus();
    [$user, $appServer, $site, $cluster, $binding] = provisioningManagedValkeyPrimary();

    $job = new ProvisionManagedDatabaseJob(
        (string) $cluster->id,
        (string) $binding->id,
        (string) $appServer->id,
    );
    $job->handle(app(DatabaseRouter::class));

    $run = ConsoleAction::query()
        ->forSubject($site)
        ->ofKind(ManagedDatabaseProvisionConsole::KIND)
        ->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(ConsoleAction::STATUS_RUNNING);

    $output = collect($run->lines())->pluck('line')->implode("\n");
    expect($output)->toContain('Create accepted')
        ->and($output)->toContain('engine=valkey')
        ->and($output)->toContain('status=creating');

    Queue::assertPushed(ProvisionManagedDatabaseJob::class, function (ProvisionManagedDatabaseJob $queued) use ($run, $cluster, $binding, $appServer): bool {
        return $queued->cloudDatabaseId === (string) $cluster->id
            && $queued->siteBindingId === (string) $binding->id
            && $queued->serverId === (string) $appServer->id
            && $queued->attempt === 2
            && $queued->seededConsoleRunId === (string) $run->id;
    });
});

test('managed redis detach confirm offers delete option and a detach-and-delete button', function () {
    fakeDoManagedClusterStatus();
    [$user, $appServer, $site, $cluster, $binding] = provisioningManagedValkeyPrimary();

    Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $appServer, 'site' => $site])
        ->assertSee(__('Detach & delete'))
        ->call('openDetachBindingConfirmModal', (string) $binding->id, 'Redis / Valkey')
        ->assertDispatched('close-modal', 'binding-info-modal')
        ->assertSet('bindingInfo', null)
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'detachBinding')
        ->assertSet('confirmActionModalToggleLabel', __('Also delete the managed Valkey cluster'))
        ->assertSee(__('Detach & delete'))
        ->assertSee(__('Also delete the managed Valkey cluster'));

    expect(CloudDatabase::query()->whereKey($cluster->id)->exists())->toBeTrue();
});

test('detach and delete tears down the managed redis cluster', function () {
    Queue::fake();
    fakeDoManagedClusterStatus();
    [$user, $appServer, $site, $cluster, $binding] = provisioningManagedValkeyPrimary();

    Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $appServer, 'site' => $site])
        ->call('openDetachAndDeleteBindingConfirmModal', (string) $binding->id, 'Redis / Valkey')
        ->assertDispatched('close-modal', 'binding-info-modal')
        ->assertSet('confirmActionModalToggle', true)
        ->call('confirmActionModal', true)
        ->assertSet('showConfirmActionModal', false);

    expect(SiteBinding::query()->whereKey($binding->id)->exists())->toBeFalse();

    Queue::assertPushed(TeardownCloudDatabaseJob::class, function (TeardownCloudDatabaseJob $job) use ($cluster): bool {
        return $job->cloudDatabaseId === (string) $cluster->id;
    });
});

test('setup wizard can detach and delete a provisioning managed database', function () {
    Queue::fake();
    [$user, $appServer, $site, $cluster, $binding] = provisioningManagedDatabasePrimary();

    Livewire::actingAs($user)
        ->test(SiteSetup::class, ['server' => $appServer, 'site' => $site, 'embedded' => true])
        ->call('openDetachAndDeleteBindingConfirmModal', (string) $binding->id, 'Database')
        ->assertDispatched('close-modal', 'binding-info-modal')
        ->assertSet('bindingInfo', null)
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalToggle', true)
        ->assertSee(__('Also delete the managed database cluster'))
        ->call('confirmActionModal', true)
        ->assertSet('showConfirmActionModal', false);

    expect(SiteBinding::query()->whereKey($binding->id)->exists())->toBeFalse();

    Queue::assertPushed(TeardownCloudDatabaseJob::class, function (TeardownCloudDatabaseJob $job) use ($cluster): bool {
        return $job->cloudDatabaseId === (string) $cluster->id;
    });
});

test('resource map can seed a console action when verifying a binding', function () {
    Queue::fake();
    fakeDoManagedClusterStatus();
    [$user, $appServer, $site, $cluster, $binding] = provisioningManagedValkeyPrimary();
    $binding->forceFill(['status' => SiteBinding::STATUS_CONFIGURED])->save();

    Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $appServer, 'site' => $site])
        ->call('verifyBinding', (string) $binding->id)
        ->assertHasNoErrors();

    expect(ConsoleAction::query()->forSubject($site)->ofKind('binding_validate')->exists())->toBeTrue();
    expect($cluster->id)->not->toBeEmpty();
});

test('edit on a managed redis binding opens the manage screen not provision', function () {
    fakeDoManagedClusterStatus();
    [$user, $appServer, $site, $cluster, $binding] = configuredManagedValkeyPrimary();

    Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $appServer, 'site' => $site])
        ->call('openBindingModal', 'redis', 'attach', (string) $binding->id)
        ->assertSet('bindingModalMode', 'edit')
        ->assertSet('bindingModalBindingId', (string) $binding->id)
        ->assertSee(__('Edit Redis / Valkey'))
        ->assertSee(__('Managed Valkey'))
        ->assertSee(__('View details'))
        ->assertSee(__('Resize cluster'))
        ->assertSee(__('Detach & delete'))
        ->assertDontSee(__('Where should it live?'));

    expect($cluster->id)->not->toBeEmpty();
});

/**
 * @return array{0: User, 1: Server, 2: Site, 3: CloudDatabase, 4: SiteBinding}
 */
function configuredManagedValkeyPrimary(): array
{
    [$user, $appServer, $site, $cluster, $binding] = provisioningManagedValkeyPrimary();
    $cluster->forceFill(['status' => CloudDatabase::STATUS_ACTIVE])->save();
    $binding->forceFill(['status' => SiteBinding::STATUS_CONFIGURED])->save();

    return [$user, $appServer, $site, $cluster, $binding];
}

test('edit resize confirm queues an upsize of the managed redis cluster', function () {
    Queue::fake();
    fakeDoManagedClusterStatus();
    [$user, $appServer, $site, $cluster, $binding] = configuredManagedValkeyPrimary();

    Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $appServer, 'site' => $site])
        ->call('openBindingModal', 'redis', 'attach', (string) $binding->id)
        ->set('bindingForm.size', 'db-s-2vcpu-4gb')
        ->call('openResizeManagedBindingConfirmModal')
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'resizeManagedBinding')
        ->assertSee(__('Upsize this cluster?'))
        ->call('confirmActionModal')
        ->assertSet('showConfirmActionModal', false);

    expect($binding->fresh()->config['resizing_to'] ?? null)->toBe('db-s-2vcpu-4gb');

    Queue::assertPushed(ResizeManagedDatabaseJob::class, function (ResizeManagedDatabaseJob $job) use ($cluster, $binding): bool {
        return $job->cloudDatabaseId === (string) $cluster->id
            && $job->siteBindingId === (string) $binding->id
            && $job->size === 'db-s-2vcpu-4gb';
    });
});

test('resize job polls until online and stamps the new plan', function () {
    Queue::fake();
    fakeDoManagedResizeSequence('do-valkey-test');
    [$user, $appServer, $site, $cluster, $binding] = configuredManagedValkeyPrimary();
    $binding->forceFill([
        'config' => array_merge($binding->config ?? [], ['resizing_to' => 'db-s-2vcpu-4gb']),
    ])->save();

    $job = new ResizeManagedDatabaseJob((string) $cluster->id, (string) $binding->id, 'db-s-2vcpu-4gb');
    $job->handle(app(DatabaseRouter::class));

    expect($cluster->fresh()->size)->toBe('db-s-1vcpu-2gb');

    $run = ConsoleAction::query()
        ->forSubject($site)
        ->ofKind(ManagedDatabaseProvisionConsole::KIND_RESIZE)
        ->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(ConsoleAction::STATUS_RUNNING);

    Queue::assertPushed(ResizeManagedDatabaseJob::class, function (ResizeManagedDatabaseJob $queued) use ($run): bool {
        return $queued->attempt === 2
            && $queued->size === 'db-s-2vcpu-4gb'
            && $queued->seededConsoleRunId === (string) $run->id;
    });

    $followUp = new ResizeManagedDatabaseJob(
        (string) $cluster->id,
        (string) $binding->id,
        'db-s-2vcpu-4gb',
        2,
        (string) $run->id,
    );
    $followUp->handle(app(DatabaseRouter::class));

    expect($cluster->fresh()->size)->toBe('db-s-2vcpu-4gb')
        ->and($binding->fresh()->config['size'] ?? null)->toBe('db-s-2vcpu-4gb')
        ->and($binding->fresh()->config['resizing_to'] ?? null)->toBeNull();

    expect($run->fresh()->status)->toBe(ConsoleAction::STATUS_COMPLETED);
    expect($user->id)->not->toBeEmpty();
});

function fakeDoManagedResizeSequence(string $id): void
{
    $layouts = [
        ['num_nodes' => 1, 'sizes' => [
            'db-s-1vcpu-1gb', 'db-s-1vcpu-2gb', 'db-s-2vcpu-4gb', 'db-s-4vcpu-8gb',
        ]],
    ];
    $cluster = static fn (string $status): array => [
        'database' => [
            'id' => $id,
            'status' => $status,
            'engine' => 'valkey',
            'connection' => [
                'host' => 'valkey.example.ondigitalocean.com',
                'port' => 25061,
                'user' => 'default',
                'password' => 'secret',
                'database' => 'default',
                'ssl' => true,
            ],
        ],
    ];

    Http::fake([
        'https://api.digitalocean.com/v2/databases/options*' => Http::response([
            'options' => [
                'valkey' => [
                    'regions' => ['sfo3', 'nyc3'],
                    'versions' => ['8'],
                    'default_version' => '8',
                    'layouts' => $layouts,
                ],
            ],
        ], 200),
        'https://api.digitalocean.com/v2/databases/'.$id => Http::sequence()
            ->push($cluster('resizing'), 200)
            ->push($cluster('resizing'), 200)
            ->push($cluster('online'), 200),
    ]);
}

test('detach without delete leaves the managed redis cluster', function () {
    Queue::fake();
    fakeDoManagedClusterStatus();
    [$user, $appServer, $site, $cluster, $binding] = provisioningManagedValkeyPrimary();

    Livewire::actingAs($user)
        ->test(ResourceMap::class, ['server' => $appServer, 'site' => $site])
        ->call('openDetachBindingConfirmModal', (string) $binding->id, 'Redis / Valkey')
        ->call('confirmActionModal', false);

    expect(SiteBinding::query()->whereKey($binding->id)->exists())->toBeFalse()
        ->and(CloudDatabase::query()->whereKey($cluster->id)->exists())->toBeTrue();

    Queue::assertNotPushed(TeardownCloudDatabaseJob::class);
});
