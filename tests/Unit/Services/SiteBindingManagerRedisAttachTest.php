<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SiteBindingManagerRedisAttachTest;

use App\Models\CloudDatabase;
use App\Models\Organization;
use App\Models\PrivateNetwork;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Database\Jobs\ProvisionDedicatedRedisVmJob;
use App\Modules\Deploy\Services\SiteBindingManager;
use App\Notifications\DedicatedResourceProvisionFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * @return array{0: Organization, 1: Site, 2: Server}
 */
function redisAttachFixture(): array
{
    $org = Organization::factory()->create();
    $appServer = Server::factory()->create([
        'organization_id' => $org->id,
        'ip_address' => '203.0.113.10',
    ]);
    $site = Site::factory()->create([
        'server_id' => $appServer->id,
        'organization_id' => $org->id,
        'user_id' => $appServer->user_id,
    ]);

    return [$org, $site, $appServer];
}

function redisOn(Server $server, string $engine = 'redis'): ServerCacheService
{
    return ServerCacheService::query()->create([
        'server_id' => $server->id,
        'engine' => $engine,
        'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
        'status' => ServerCacheService::STATUS_RUNNING,
        'port' => 6379,
    ]);
}

test('dedicated cache host in the same org is attachable without a shared private network', function () {
    [$org, $site] = redisAttachFixture();
    $managedRedis = Server::factory()->create([
        'organization_id' => $org->id,
        'name' => 'cache-prod',
        'ip_address' => '203.0.113.50',
        'hosting_backend' => Server::HOSTING_BACKEND_DPLY,
        'meta' => [
            'server_role' => 'redis',
            'install_profile' => 'redis_server',
        ],
    ]);
    $svc = redisOn($managedRedis);

    $targets = app(SiteBindingManager::class)->attachableTargets($site, 'redis');

    expect($targets)->toHaveCount(1)
        ->and($targets[0]['id'])->toBe((string) $svc->id)
        ->and($targets[0]['group'])->toBe('dedicated');
});

test('redis on another app server is not attachable without a shared private network', function () {
    [$org, $site] = redisAttachFixture();
    $otherApp = Server::factory()->create([
        'organization_id' => $org->id,
        'name' => 'other-app',
        'ip_address' => '203.0.113.20',
        'meta' => ['server_role' => 'app'],
    ]);
    redisOn($otherApp);

    expect(app(SiteBindingManager::class)->attachableTargets($site, 'redis'))->toBe([]);
});

test('attaching a dedicated cache host injects its public IP', function () {
    [$org, $site] = redisAttachFixture();
    $managedRedis = Server::factory()->create([
        'organization_id' => $org->id,
        'name' => 'cache-prod',
        'ip_address' => '203.0.113.50',
        'hosting_backend' => Server::HOSTING_BACKEND_DPLY,
        'meta' => [
            'server_role' => 'redis',
            'install_profile' => 'redis_server',
        ],
    ]);
    $svc = redisOn($managedRedis);

    $binding = app(SiteBindingManager::class)->attachExisting($site, 'redis', [
        'target_id' => (string) $svc->id,
    ]);

    expect($binding->injected_env['REDIS_HOST'])->toBe('203.0.113.50')
        ->and($binding->injected_env['REDIS_PORT'])->toBe('6379')
        ->and($binding->config['source_server_id'])->toBe((string) $managedRedis->id);
});

test('dedicated cache host on the same private network uses the private IP', function () {
    [$org, $site, $appServer] = redisAttachFixture();
    $network = PrivateNetwork::query()->create([
        'organization_id' => $org->id,
        'name' => 'vpc',
        'provider' => PrivateNetwork::PROVIDER_DO,
        'ip_range' => '10.10.0.0/16',
    ]);
    $appServer->forceFill([
        'private_ip_address' => '10.10.0.10',
        'private_network_id' => $network->id,
    ])->save();

    $managedRedis = Server::factory()->create([
        'organization_id' => $org->id,
        'name' => 'cache-prod',
        'ip_address' => '203.0.113.50',
        'private_ip_address' => '10.10.0.20',
        'private_network_id' => $network->id,
        'hosting_backend' => Server::HOSTING_BACKEND_DPLY,
        'meta' => [
            'server_role' => 'redis',
            'install_profile' => 'redis_server',
        ],
    ]);
    $svc = redisOn($managedRedis);

    $manager = app(SiteBindingManager::class);
    $targets = $manager->attachableTargets($site->fresh(), 'redis');
    $binding = $manager->attachExisting($site->fresh(), 'redis', [
        'target_id' => (string) $svc->id,
    ]);

    expect($targets[0]['group'])->toBe('peer')
        ->and($binding->injected_env['REDIS_HOST'])->toBe('10.10.0.20');
});

test('managed Redis clusters in the same org appear in the attach picker', function () {
    [$org, $site] = redisAttachFixture();
    $cluster = CloudDatabase::factory()->redis()->active()->create([
        'organization_id' => $org->id,
        'name' => 'cache-prod',
        'connection' => [
            'host' => 'redis-prod.ondigitalocean.com',
            'port' => 25061,
            'password' => 'managed-secret',
        ],
    ]);

    $targets = app(SiteBindingManager::class)->attachableTargets($site, 'redis');
    $managed = collect($targets)->firstWhere('group', 'managed');

    expect($managed)->not->toBeNull()
        ->and($managed['id'])->toBe('cloud:'.$cluster->id);
});

test('attaching a managed Redis cluster injects its connection host', function () {
    [$org, $site] = redisAttachFixture();
    $cluster = CloudDatabase::factory()->redis()->active()->create([
        'organization_id' => $org->id,
        'name' => 'cache-prod',
        'connection' => [
            'host' => 'redis-prod.ondigitalocean.com',
            'port' => 25061,
            'password' => 'managed-secret',
        ],
    ]);

    $binding = app(SiteBindingManager::class)->attachExisting($site, 'redis', [
        'target_id' => 'cloud:'.$cluster->id,
    ]);

    expect($binding->target_type)->toBe('cloud_database')
        ->and($binding->target_id)->toBe((string) $cluster->id)
        ->and($binding->injected_env['REDIS_HOST'])->toBe('redis-prod.ondigitalocean.com')
        ->and($binding->injected_env['REDIS_PORT'])->toBe('25061')
        ->and($binding->injected_env['REDIS_PASSWORD'])->toBe('managed-secret')
        ->and($binding->injected_env['REDIS_SCHEME'])->toBe('tls')
        ->and($binding->injected_env['REDIS_URL'])->toBe('rediss://default:managed-secret@redis-prod.ondigitalocean.com:25061');
});

test('a stale managed redis binding still contributes tls env at deploy', function () {
    [$org, $site] = redisAttachFixture();
    $cluster = CloudDatabase::factory()->redis()->active()->create([
        'organization_id' => $org->id,
        'name' => 'cache-prod',
        'connection' => [
            'host' => 'redis-prod.ondigitalocean.com',
            'port' => 25061,
            'password' => 'managed-secret',
        ],
    ]);

    $binding = SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'redis',
        'mode' => 'attach_existing',
        'status' => SiteBinding::STATUS_CONFIGURED,
        'name' => 'primary',
        'target_type' => 'cloud_database',
        'target_id' => (string) $cluster->id,
        'injected_env' => [
            'REDIS_HOST' => 'redis-prod.ondigitalocean.com',
            'REDIS_PORT' => '25061',
            'REDIS_PASSWORD' => 'managed-secret',
            'REDIS_CLIENT' => 'phpredis',
        ],
        'config' => ['engine' => 'redis', 'managed' => true],
    ]);

    expect($binding->connectionEnv())
        ->toHaveKey('REDIS_CLIENT', 'phpredis')
        ->toHaveKey('REDIS_SCHEME', 'tls')
        ->toHaveKey('REDIS_URL', 'rediss://default:managed-secret@redis-prod.ondigitalocean.com:25061');
});

test('wireServerCacheBinding injects redis env for a dedicated cache host', function () {
    [$org, $site] = redisAttachFixture();
    $managedRedis = Server::factory()->create([
        'organization_id' => $org->id,
        'name' => 'cache-prod',
        'ip_address' => '203.0.113.50',
        'meta' => [
            'server_role' => 'redis',
            'install_profile' => 'redis_server',
        ],
    ]);
    $svc = redisOn($managedRedis);
    $svc->forceFill(['auth_password' => 'secret-pass'])->save();

    $binding = SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'redis',
        'mode' => 'provision_new',
        'status' => SiteBinding::STATUS_PROVISIONING,
        'name' => 'primary',
        'target_type' => 'server_cache_service',
        'target_id' => (string) $svc->id,
        'injected_env' => [],
        'config' => [
            'engine' => 'redis',
            'connection' => '',
            'placement' => 'cache_vm',
        ],
    ]);

    app(SiteBindingManager::class)->wireServerCacheBinding($binding->fresh(), $svc->fresh(), $site);

    $binding->refresh();
    expect($binding->status)->toBe(SiteBinding::STATUS_CONFIGURED)
        ->and($binding->injected_env['REDIS_HOST'])->toBe('203.0.113.50')
        ->and($binding->injected_env['REDIS_PORT'])->toBe('6379')
        ->and($binding->injected_env['REDIS_PASSWORD'])->toBe('secret-pass')
        ->and($binding->injected_env['REDIS_CLIENT'])->toBe('phpredis');
});

test('provision dedicated redis job waits until the box is ready then wires', function () {
    [$org, $site] = redisAttachFixture();
    $managedRedis = Server::factory()->create([
        'organization_id' => $org->id,
        'name' => 'cache-prod',
        'ip_address' => '203.0.113.50',
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'meta' => [
            'server_role' => 'redis',
            'install_profile' => 'redis_server',
        ],
    ]);
    $svc = ServerCacheService::query()->create([
        'server_id' => $managedRedis->id,
        'engine' => 'redis',
        'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
        'status' => ServerCacheService::STATUS_INSTALLING,
        'port' => 6379,
        'auth_password' => 'wired-pass',
    ]);
    $binding = SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'redis',
        'mode' => 'provision_new',
        'status' => SiteBinding::STATUS_PROVISIONING,
        'name' => 'primary',
        'target_type' => 'server_cache_service',
        'target_id' => (string) $svc->id,
        'injected_env' => [],
        'config' => ['placement' => 'cache_vm', 'connection' => ''],
    ]);

    (new ProvisionDedicatedRedisVmJob(
        (string) $managedRedis->id,
        (string) $site->id,
        (string) $svc->id,
        (string) $binding->id,
    ))->handle(app(SiteBindingManager::class));

    expect($svc->fresh()->status)->toBe(ServerCacheService::STATUS_RUNNING)
        ->and($binding->fresh()->status)->toBe(SiteBinding::STATUS_CONFIGURED)
        ->and($binding->fresh()->injected_env['REDIS_HOST'])->toBe('203.0.113.50')
        ->and($binding->fresh()->injected_env['REDIS_PASSWORD'])->toBe('wired-pass');
});

test('failed dedicated redis provision removes the orphan vm and notifies', function () {
    Notification::fake();
    [$org, $site] = redisAttachFixture();
    $cacheServer = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $site->user_id,
        'name' => 'acme-redis',
        'status' => Server::STATUS_ERROR,
        'setup_status' => Server::SETUP_STATUS_FAILED,
        'meta' => [
            'server_role' => 'redis',
            'install_profile' => 'redis_server',
            'provision_error' => ['message' => 'size is not available in this region'],
        ],
    ]);
    $svc = redisOn($cacheServer);
    $binding = SiteBinding::query()->create([
        'site_id' => $site->id,
        'type' => 'redis',
        'mode' => 'provision_new',
        'status' => SiteBinding::STATUS_PROVISIONING,
        'name' => 'primary',
        'target_type' => 'server_cache_service',
        'target_id' => (string) $svc->id,
        'injected_env' => [],
        'config' => [
            'placement' => 'cache_vm',
            'cache_vm_server_id' => (string) $cacheServer->id,
            'vm_size' => 's-2vcpu-4gb',
            'region' => 'sfo',
        ],
    ]);

    (new ProvisionDedicatedRedisVmJob(
        (string) $cacheServer->id,
        (string) $site->id,
        (string) $svc->id,
        (string) $binding->id,
    ))->handle(app(SiteBindingManager::class));

    expect(Server::query()->find($cacheServer->id))->toBeNull()
        ->and($binding->fresh()->status)->toBe(SiteBinding::STATUS_ERROR)
        ->and($binding->fresh()->provisionServerId())->toBeNull()
        ->and($binding->fresh()->last_error)->toContain('size is not available in this region');

    Notification::assertSentTo($site->user, DedicatedResourceProvisionFailedNotification::class);
});
