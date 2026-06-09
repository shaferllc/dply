<?php

namespace Tests\Feature\CacheServiceNetworkExposureTest;

use App\Jobs\ApplyFirewallJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerFirewallRule;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\CacheServiceNetworkExposure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/** @return array{User, Server, ServerCacheService} */
function ownerWithRedisInstance(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'ssh_user' => 'root',
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1lZDI1NTE5AAAA\n-----END OPENSSH PRIVATE KEY-----",
    ]);

    $row = ServerCacheService::query()->create([
        'server_id' => $server->id,
        'engine' => 'redis',
        'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
        'status' => ServerCacheService::STATUS_RUNNING,
        'port' => 6379,
        'auth_password' => 'has-a-password',
    ]);

    return [$user, $server, $row];
}

function fakeSuccessfulSsh(): void
{
    $mock = \Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $mock->shouldReceive('runInlineBash')
        ->andReturn(new ProcessOutput(buffer: '', exitCode: 0));
    app()->instance(ExecuteRemoteTaskOnServer::class, $mock);
}

test('expose writes firewall rule and dispatches apply', function () {
    Queue::fake();
    fakeSuccessfulSsh();
    [$user, $server, $row] = ownerWithRedisInstance();

    $exposure = app(CacheServiceNetworkExposure::class);
    $exposure->expose($server, $row, '10.0.0.0/8', $user->id);

    $rule = ServerFirewallRule::query()
        ->where('server_id', $server->id)
        ->whereJsonContains('tags', CacheServiceNetworkExposure::firewallRuleTag($row))
        ->first();

    expect($rule)->not->toBeNull('Expose flow must persist a firewall rule.');
    expect($rule->source)->toBe('10.0.0.0/8');
    expect((int) $rule->port)->toBe(6379);
    expect($rule->protocol)->toBe('tcp');
    expect($rule->action)->toBe('allow');
    expect((bool) $rule->enabled)->toBeTrue();

    Queue::assertPushed(ApplyFirewallJob::class, fn ($job) => $job->serverId === $server->id);
});

test('expose rejects overly broad source', function () {
    Queue::fake();
    fakeSuccessfulSsh();
    [, $server, $row] = ownerWithRedisInstance();

    $exposure = app(CacheServiceNetworkExposure::class);

    $this->expectException(\InvalidArgumentException::class);
    $exposure->expose($server, $row, '0.0.0.0/0', null);
});

test('lockdown removes firewall rule and dispatches apply', function () {
    Queue::fake();
    fakeSuccessfulSsh();
    [$user, $server, $row] = ownerWithRedisInstance();

    // Pre-seed an exposed rule.
    ServerFirewallRule::query()->create([
        'server_id' => $server->id,
        'name' => 'Cache · Redis (default)',
        'port' => 6379,
        'protocol' => 'tcp',
        'source' => '10.0.0.0/8',
        'action' => 'allow',
        'enabled' => true,
        'sort_order' => 1,
        'tags' => ['dply-cache', CacheServiceNetworkExposure::firewallRuleTag($row)],
    ]);

    $exposure = app(CacheServiceNetworkExposure::class);
    $exposure->lockdown($server, $row, $user->id);

    $remaining = ServerFirewallRule::query()
        ->where('server_id', $server->id)
        ->whereJsonContains('tags', CacheServiceNetworkExposure::firewallRuleTag($row))
        ->count();
    expect($remaining)->toBe(0, 'Lockdown should delete the managed firewall rule.');

    Queue::assertPushed(ApplyFirewallJob::class);
});

test('expose rejects unsupported engine', function () {
    fakeSuccessfulSsh();
    [, $server, $redisRow] = ownerWithRedisInstance();

    $memcached = ServerCacheService::query()->create([
        'server_id' => $server->id,
        'engine' => 'memcached',
        'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
        'status' => ServerCacheService::STATUS_RUNNING,
        'port' => 11211,
    ]);

    $exposure = app(CacheServiceNetworkExposure::class);

    $this->expectException(\InvalidArgumentException::class);
    $exposure->expose($server, $memcached, '10.0.0.0/8', null);
});
