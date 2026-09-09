<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Servers;

use App\Jobs\AddEdgeProxyJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerRemoteAccessEvent;
use App\Models\User;
use App\Services\Servers\ServerRemoteAccessContext;
use App\Services\Servers\ServerRemoteAccessLogger;
use App\Services\Servers\ServerSshAccessTimeline;
use App\Services\SshConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('remote access logger records platform ssh session under bound context', function (): void {
    Carbon::setTestNow('2026-05-30 12:00:00');

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'status' => Server::STATUS_READY,
        'ip_address' => '203.0.113.10',
        'meta' => ['host_kind' => 'vm'],
    ]);

    app()->instance(
        ServerRemoteAccessContext::class,
        ServerRemoteAccessContext::forJob(AddEdgeProxyJob::class, null),
    );

    $logger = app(ServerRemoteAccessLogger::class);
    $logger->touch($server, 'dply', SshConnection::ROLE_OPERATIONAL);
    $logger->recordCommand($server, 'echo hello');
    $logger->finishContext();

    $this->assertDatabaseHas('server_remote_access_events', [
        'server_id' => $server->id,
        'source' => 'AddEdgeProxyJob',
        'label' => 'Install edge proxy',
        'linux_user' => 'dply',
        'command_count' => 1,
        'failed' => false,
    ]);
});

test('access timeline includes platform remote access lanes and events', function (): void {
    Carbon::setTestNow('2026-05-30 12:00:00');

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm'],
    ]);

    ServerRemoteAccessEvent::query()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'linux_user' => 'dply',
        'credential_role' => SshConnection::ROLE_OPERATIONAL,
        'source' => 'AddEdgeProxyJob',
        'label' => 'Install edge proxy',
        'started_at' => now()->subHours(2),
        'finished_at' => now()->subHour(),
        'command_count' => 12,
        'failed' => false,
    ]);

    $timeline = app(ServerSshAccessTimeline::class)->forServer($server->fresh(), $user, '30d');

    expect(collect($timeline['lanes'])->contains(fn (array $lane): bool => ($lane['source'] ?? '') === 'platform'))
        ->toBeTrue()
        ->and(collect($timeline['events'])->contains(fn (array $event): bool => ($event['source'] ?? '') === 'platform'))
        ->toBeTrue();
});
