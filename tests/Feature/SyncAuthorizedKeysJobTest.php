<?php

namespace Tests\Feature\SyncAuthorizedKeysJobTest;

use App\Jobs\SyncAuthorizedKeysJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerAuthorizedKeysSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/** @return array{User, Server} */
function ownerWithServer(): array
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

    return [$user, $server];
}

test('handle marks meta completed and writes buffer on success', function () {
    [$user, $server] = ownerWithServer();
    $runId = '01TESTRUN0000000000000';

    $this->mock(ServerAuthorizedKeysSynchronizer::class, function ($mock): void {
        // Synchronizer chain: ->withOutputCallback(...)->sync(...). We invoke the captured
        // callback so the job's chunk-write code path actually runs.
        $mock->shouldReceive('withOutputCallback')
            ->once()
            ->andReturnUsing(function (callable $cb) use ($mock) {
                $cb('out', "Reading current authorized_keys for root\n");
                $cb('out', "DPLY_AUTH_EXIT:0\n");

                return $mock;
            });
        $mock->shouldReceive('sync')->once();
    });

    $job = new SyncAuthorizedKeysJob(
        serverId: $server->id,
        runId: $runId,
        userId: $user->id,
        ipAddress: '203.0.113.1',
    );

    $job->handle(app(ServerAuthorizedKeysSynchronizer::class));

    $meta = $server->fresh()->meta ?? [];
    expect(data_get($meta, config('server_ssh_keys.meta_sync_status_key')))->toBe('completed');
    expect(data_get($meta, config('server_ssh_keys.meta_sync_finished_at_key')))->not->toBeEmpty();
    expect(data_get($meta, config('server_ssh_keys.meta_sync_error_key')))->toBeNull();

    $payload = Cache::get($job->cacheKey());
    expect($payload)->toBeArray();
    expect($payload['lines'])->not->toBeEmpty();
    expect($payload['lines'])->toContain('Reading current authorized_keys for root');
    expect(in_array('> Done. authorized_keys updated successfully.', $payload['lines'], true))->toBeTrue();
});

test('handle marks meta failed and records error on throw', function () {
    [$user, $server] = ownerWithServer();
    $runId = '01TESTRUN0000000000FAIL';

    $this->mock(ServerAuthorizedKeysSynchronizer::class, function ($mock): void {
        $mock->shouldReceive('withOutputCallback')->andReturnSelf();
        $mock->shouldReceive('sync')
            ->once()
            ->andThrow(new \RuntimeException('Permission denied (publickey).'));
    });

    $job = new SyncAuthorizedKeysJob(
        serverId: $server->id,
        runId: $runId,
        userId: $user->id,
        ipAddress: null,
    );

    $job->handle(app(ServerAuthorizedKeysSynchronizer::class));

    $meta = $server->fresh()->meta ?? [];
    expect(data_get($meta, config('server_ssh_keys.meta_sync_status_key')))->toBe('failed');
    expect(data_get($meta, config('server_ssh_keys.meta_sync_error_key')))->toBe('Permission denied (publickey).');

    $payload = Cache::get($job->cacheKey());
    expect($payload)->toBeArray();
    $errorLines = array_filter($payload['lines'], fn ($l) => str_contains($l, 'ERROR'));
    expect($errorLines)->not->toBeEmpty('Streaming buffer should include the error line.');
});
