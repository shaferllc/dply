<?php


namespace Tests\Feature\ApplyFirewallJobTest;
use App\Jobs\ApplyFirewallJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerFirewallApplyRecorder;
use App\Services\Servers\ServerFirewallAuditLogger;
use App\Services\Servers\ServerFirewallProvisioner;
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

test('handle marks meta completed and writes buffer on success', function () {
    [$user, $server] = ownerWithServer();
    $runId = '01TESTAPPLY00000000000';

    $this->mock(ServerFirewallProvisioner::class, function ($mock): void {
        $mock->shouldReceive('withOutputCallback')
            ->once()
            ->andReturnUsing(function (callable $cb) use ($mock) {
                $cb('out', '> Applying UFW rules to dply@host …');
                $cb('out', '> ufw allow 22/tcp');
                $cb('out', '  Rules updated');
                $cb('out', '> ufw --force enable');
                $cb('out', '  Firewall is active and enabled on system startup');

                return $mock;
            });
        $mock->shouldReceive('apply')->once()->andReturn('Applied SSH and reload OK');
    });

    // The audit logger and apply recorder still run from inside the job. Stub them so we
    // don't depend on their concrete behavior here — that's covered by their own tests.
    $this->mock(ServerFirewallAuditLogger::class, function ($mock): void {
        $mock->shouldReceive('record')->once();
    });
    $this->mock(ServerFirewallApplyRecorder::class, function ($mock): void {
        $mock->shouldReceive('recordSuccess')->once();
    });

    $job = new ApplyFirewallJob(serverId: $server->id, runId: $runId, userId: $user->id);
    $job->handle(
        app(ServerFirewallProvisioner::class),
        app(ServerFirewallAuditLogger::class),
        app(ServerFirewallApplyRecorder::class),
    );

    $meta = $server->fresh()->meta ?? [];
    expect(data_get($meta, config('server_firewall.meta_apply_status_key')))->toBe('completed');
    expect(data_get($meta, config('server_firewall.meta_apply_finished_at_key')))->not->toBeEmpty();
    expect(data_get($meta, config('server_firewall.meta_apply_error_key')))->toBeNull();

    $payload = Cache::get($job->cacheKey());
    expect($payload)->toBeArray();
    expect($payload['lines'])->toContain('> ufw allow 22/tcp');
    expect($payload['lines'])->toContain('> Apply complete.');
});

test('handle marks meta failed and records error on throw', function () {
    [$user, $server] = ownerWithServer();
    $runId = '01TESTAPPLYFAIL0000000';

    $this->mock(ServerFirewallProvisioner::class, function ($mock): void {
        $mock->shouldReceive('withOutputCallback')->andReturnSelf();
        $mock->shouldReceive('apply')
            ->once()
            ->andThrow(new \RuntimeException('Permission denied (publickey).'));
    });
    $this->mock(ServerFirewallApplyRecorder::class, function ($mock): void {
        $mock->shouldReceive('recordFailure')->once();
    });

    $job = new ApplyFirewallJob(serverId: $server->id, runId: $runId, userId: $user->id);
    $job->handle(
        app(ServerFirewallProvisioner::class),
        app(ServerFirewallAuditLogger::class),
        app(ServerFirewallApplyRecorder::class),
    );

    $meta = $server->fresh()->meta ?? [];
    expect(data_get($meta, config('server_firewall.meta_apply_status_key')))->toBe('failed');
    expect(data_get($meta, config('server_firewall.meta_apply_error_key')))->toBe('Permission denied (publickey).');

    $payload = Cache::get($job->cacheKey());
    expect($payload)->toBeArray();
    $errorLines = array_filter($payload['lines'], fn ($l) => str_contains($l, 'ERROR'));
    expect($errorLines)->not->toBeEmpty();
});