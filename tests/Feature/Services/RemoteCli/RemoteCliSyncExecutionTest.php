<?php

declare(strict_types=1);

namespace Tests\Feature\Services\RemoteCli\RemoteCliSyncExecutionTest;

use App\Jobs\RunRemoteCliInBackgroundJob;
use App\Models\Organization;
use App\Models\RemoteCliRun;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\RemoteCli\Kind;
use App\Services\RemoteCli\RemoteCliPermissionDeniedException;
use App\Services\RemoteCli\RemoteCliPermissions;
use App\Services\RemoteCli\RiskLevel;
use App\Services\RemoteCli\SiteAuditWriter;
use App\Services\RemoteCli\WpCli;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Mockery;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Async wp-cli runs settle via RunRemoteCliInBackgroundJob; allow it
    // to execute inline while the global Feature test harness fakes SSH.
    Queue::getFacadeRoot()->except([RunRemoteCliInBackgroundJob::class]);
});

afterEach(function () {
    Mockery::close();
});
function makeMemberUserWithSite(string $role = 'admin'): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'document_root' => '/home/dply/wordpress/current',
    ]);

    return [$user, $org, $server, $site];
}
function wp(?ExecuteRemoteTaskOnServer $executor = null): WpCli
{
    return new WpCli(
        $executor ?? Mockery::mock(ExecuteRemoteTaskOnServer::class),
        new RemoteCliPermissions,
        new SiteAuditWriter,
    );
}
test('instant command runs sync and persists completed run', function () {
    [$user, , , $site] = makeMemberUserWithSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->once()
        ->withArgs(function ($s, string $name, string $bash, callable $cb, ?int $timeout) use ($site): bool {
            expect($s->id)->toBe($site->server->id);
            $this->assertStringContainsString('plugin list', $bash);
            $this->assertStringContainsString("'/home/dply/wordpress/current'", $bash);
            expect($timeout)->toBe(WpCli::SYNC_TIMEOUT_SECONDS);
            // Drive the chunk callback to simulate streamed output.
            $cb('out', '[]');

            return true;
        })
        ->andReturn(new ProcessOutput('[]', 0, false));

    $result = wp($executor)->run($site, 'plugin list', ['--format=json'], $user);

    expect($result->isCompleted())->toBeTrue();
    expect($result->exitCode())->toBe(0);
    expect($result->stdout())->toBe('[]');

    $run = RemoteCliRun::query()->sole();
    expect($run->kind)->toBe(Kind::Wp);
    expect($run->risk)->toBe(RiskLevel::Read);
    expect($run->mode)->toBe(RemoteCliRun::MODE_SYNC);
    expect($run->status)->toBe(RemoteCliRun::STATUS_COMPLETED);
    expect($run->queued_by_user_id)->toBe($user->id);
});
test('read run does not emit audit event', function () {
    [$user, , , $site] = makeMemberUserWithSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->andReturn(new ProcessOutput('[]', 0, false));

    wp($executor)->run($site, 'plugin list', [], $user);

    expect(SiteAuditEvent::query()->count())->toBe(0, 'Read commands must never write audit rows (they would explode counts).');
});
test('mutating recoverable async run emits audit event on settle', function () {
    // `cron event run` is mutating-recoverable but NOT on the
    // INSTANT allowlist, so it routes async. The dispatched job
    // runs inline in the test queue; we bind the executor mock
    // into the container so the job picks it up rather than
    // resolving a real one and trying to SSH.
    [$user, , , $site] = makeMemberUserWithSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->andReturn(new ProcessOutput('Executed wp_version_check', 0, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    wp($executor)->run($site, 'cron event run', ['wp_version_check'], $user);

    $event = SiteAuditEvent::query()->sole();
    expect($event->action)->toBe('wp_cli_run');
    expect($event->risk)->toBe(RiskLevel::MutatingRecoverable);
    expect($event->transport)->toBe(SiteAuditEvent::TRANSPORT_WEB);
    expect($event->result_status)->toBe(SiteAuditEvent::RESULT_SUCCESS);
    expect($event->user_id)->toBe($user->id);
    expect($event->payload['command'])->toBe('cron event run');
});
test('failed async run emits audit event with failure status', function () {
    [$user, , , $site] = makeMemberUserWithSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runInlineBashWithOutputCallback')
        ->andReturn(new ProcessOutput('boom', 1, false));
    app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

    wp($executor)->run($site, 'cron event run', [], $user);

    $event = SiteAuditEvent::query()->sole();
    expect($event->result_status)->toBe(SiteAuditEvent::RESULT_FAILURE);
});
test('destructive command from member is denied', function () {
    // A "member" role is not admin/owner — so destructive commands must
    // be blocked at the gate before any persistence happens.
    [$user, , , $site] = makeMemberUserWithSite(role: 'member');

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldNotReceive('runInlineBashWithOutputCallback');

    $this->expectException(RemoteCliPermissionDeniedException::class);

    try {
        wp($executor)->run($site, 'db drop', [], $user);
    } finally {
        // No row should have been persisted; the gate runs first.
        expect(RemoteCliRun::query()->count())->toBe(0);
        expect(SiteAuditEvent::query()->count())->toBe(0);
    }
});
test('destructive command from admin is allowed and dispatches async', function () {
    Bus::fake();

    [$user, , , $site] = makeMemberUserWithSite(role: 'admin');

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldNotReceive('runInlineBashWithOutputCallback');

    $result = wp($executor)->run($site, 'db drop', ['--yes'], $user);

    expect($result->isQueued())->toBeTrue();

    $run = RemoteCliRun::query()->sole();
    expect($run->risk)->toBe(RiskLevel::Destructive);
    expect($run->mode)->toBe(RemoteCliRun::MODE_ASYNC);

    Bus::assertDispatched(RunRemoteCliInBackgroundJob::class,
        fn (RunRemoteCliInBackgroundJob $job): bool => $job->remoteCliRunId === $run->id);
});
test('system run with no user bypasses permission gate', function () {
    // Scaffold pipelines apply hardening defaults without a user
    // attribution. The gate must permit these.
    Bus::fake();
    [, , , $site] = makeMemberUserWithSite();

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldNotReceive('runInlineBashWithOutputCallback');

    $result = wp($executor)->run($site, 'db drop', [], queuedBy: null);

    expect($result->isQueued())->toBeTrue();
    Bus::assertDispatched(RunRemoteCliInBackgroundJob::class);
});
