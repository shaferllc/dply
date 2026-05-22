<?php


namespace Tests\Feature\PreviewDriftJobTest;
use App\Jobs\PreviewDriftJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerAuthorizedKeysDiffPreview;
use Illuminate\Support\Facades\Cache;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

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

test('handle marks meta completed and writes buffer and diff result', function () {
    [, $server] = ownerWithServer();
    $runId = '01TESTRUN0000000000000';

    $this->mock(ServerAuthorizedKeysDiffPreview::class, function ($mock): void {
        $mock->shouldReceive('withOutputCallback')
            ->once()
            ->andReturnUsing(function (callable $cb) use ($mock) {
                $cb('out', '> Comparing 1 target user(s): root');
                $cb('out', '> Reading authorized_keys for root …');
                $cb('out', '  root: 1 remote, 1 desired · +0 -0');
                $cb('out', '> Done.');

                return $mock;
            });
        $mock->shouldReceive('diffPerUser')
            ->once()
            ->andReturn([
                'root' => ['remote' => [], 'desired' => [], 'added' => [], 'removed' => []],
            ]);
    });

    $job = new PreviewDriftJob(serverId: $server->id, runId: $runId);
    $job->handle(app(ServerAuthorizedKeysDiffPreview::class));

    $meta = $server->fresh()->meta ?? [];
    expect(data_get($meta, config('server_ssh_keys.meta_drift_status_key')))->toBe('completed');
    expect(data_get($meta, config('server_ssh_keys.meta_drift_finished_at_key')))->not->toBeEmpty();

    $payload = Cache::get($job->cacheKey());
    expect($payload)->toBeArray();
    expect($payload['lines'])->toContain('> Done.');
    expect($payload['lines'])->toContain('> Done. Diff computed.');
    expect($payload)->toHaveKey('diff_result');
    expect($payload['diff_result'])->toHaveKey('root');
});

test('handle marks meta failed and records error on throw', function () {
    [, $server] = ownerWithServer();
    $runId = '01TESTRUNFAILEDXXXXXXX';

    $this->mock(ServerAuthorizedKeysDiffPreview::class, function ($mock): void {
        $mock->shouldReceive('withOutputCallback')->andReturnSelf();
        $mock->shouldReceive('diffPerUser')
            ->once()
            ->andThrow(new \RuntimeException('Permission denied (publickey).'));
    });

    $job = new PreviewDriftJob(serverId: $server->id, runId: $runId);
    $job->handle(app(ServerAuthorizedKeysDiffPreview::class));

    $meta = $server->fresh()->meta ?? [];
    expect(data_get($meta, config('server_ssh_keys.meta_drift_status_key')))->toBe('failed');
    expect(data_get($meta, config('server_ssh_keys.meta_drift_error_key')))->toBe('Permission denied (publickey).');

    $payload = Cache::get($job->cacheKey());
    expect($payload)->toBeArray();
    $errorLines = array_filter($payload['lines'], fn ($l) => str_contains($l, 'ERROR'));
    expect($errorLines)->not->toBeEmpty();
});