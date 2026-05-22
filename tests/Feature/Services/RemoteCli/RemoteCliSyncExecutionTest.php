<?php

declare(strict_types=1);

namespace Tests\Feature\Services\RemoteCli;

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
use Mockery;
use Tests\TestCase;

/**
 * End-to-end checks on the sync + async dispatch + audit + permission
 * gate flow. Built on top of the PR 1 sync path with PR 2's gate /
 * audit / streaming additions.
 */
class RemoteCliSyncExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeMemberUserWithSite(string $role = 'admin'): array
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

    private function wp(?ExecuteRemoteTaskOnServer $executor = null): WpCli
    {
        return new WpCli(
            $executor ?? Mockery::mock(ExecuteRemoteTaskOnServer::class),
            new RemoteCliPermissions,
            new SiteAuditWriter,
        );
    }

    public function test_instant_command_runs_sync_and_persists_completed_run(): void
    {
        [$user, , , $site] = $this->makeMemberUserWithSite();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBashWithOutputCallback')
            ->once()
            ->withArgs(function ($s, string $name, string $bash, callable $cb, ?int $timeout) use ($site): bool {
                $this->assertSame($site->server->id, $s->id);
                $this->assertStringContainsString('plugin list', $bash);
                $this->assertStringContainsString("'/home/dply/wordpress/current'", $bash);
                $this->assertSame(WpCli::SYNC_TIMEOUT_SECONDS, $timeout);
                // Drive the chunk callback to simulate streamed output.
                $cb('out', '[]');

                return true;
            })
            ->andReturn(new ProcessOutput('[]', 0, false));

        $result = $this->wp($executor)->run($site, 'plugin list', ['--format=json'], $user);

        $this->assertTrue($result->isCompleted());
        $this->assertSame(0, $result->exitCode());
        $this->assertSame('[]', $result->stdout());

        $run = RemoteCliRun::query()->sole();
        $this->assertSame(Kind::Wp, $run->kind);
        $this->assertSame(RiskLevel::Read, $run->risk);
        $this->assertSame(RemoteCliRun::MODE_SYNC, $run->mode);
        $this->assertSame(RemoteCliRun::STATUS_COMPLETED, $run->status);
        $this->assertSame($user->id, $run->queued_by_user_id);
    }

    public function test_read_run_does_not_emit_audit_event(): void
    {
        [$user, , , $site] = $this->makeMemberUserWithSite();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBashWithOutputCallback')
            ->andReturn(new ProcessOutput('[]', 0, false));

        $this->wp($executor)->run($site, 'plugin list', [], $user);

        $this->assertSame(0, SiteAuditEvent::query()->count(),
            'Read commands must never write audit rows (they would explode counts).');
    }

    public function test_mutating_recoverable_async_run_emits_audit_event_on_settle(): void
    {
        // `cron event run` is mutating-recoverable but NOT on the
        // INSTANT allowlist, so it routes async. The dispatched job
        // runs inline in the test queue; we bind the executor mock
        // into the container so the job picks it up rather than
        // resolving a real one and trying to SSH.
        [$user, , , $site] = $this->makeMemberUserWithSite();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBashWithOutputCallback')
            ->andReturn(new ProcessOutput('Executed wp_version_check', 0, false));
        app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

        $this->wp($executor)->run($site, 'cron event run', ['wp_version_check'], $user);

        $event = SiteAuditEvent::query()->sole();
        $this->assertSame('wp_cli_run', $event->action);
        $this->assertSame(RiskLevel::MutatingRecoverable, $event->risk);
        $this->assertSame(SiteAuditEvent::TRANSPORT_WEB, $event->transport);
        $this->assertSame(SiteAuditEvent::RESULT_SUCCESS, $event->result_status);
        $this->assertSame($user->id, $event->user_id);
        $this->assertSame('cron event run', $event->payload['command']);
    }

    public function test_failed_async_run_emits_audit_event_with_failure_status(): void
    {
        [$user, , , $site] = $this->makeMemberUserWithSite();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldReceive('runInlineBashWithOutputCallback')
            ->andReturn(new ProcessOutput('boom', 1, false));
        app()->instance(ExecuteRemoteTaskOnServer::class, $executor);

        $this->wp($executor)->run($site, 'cron event run', [], $user);

        $event = SiteAuditEvent::query()->sole();
        $this->assertSame(SiteAuditEvent::RESULT_FAILURE, $event->result_status);
    }

    public function test_destructive_command_from_member_is_denied(): void
    {
        // A "member" role is not admin/owner — so destructive commands must
        // be blocked at the gate before any persistence happens.
        [$user, , , $site] = $this->makeMemberUserWithSite(role: 'member');

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldNotReceive('runInlineBashWithOutputCallback');

        $this->expectException(RemoteCliPermissionDeniedException::class);

        try {
            $this->wp($executor)->run($site, 'db drop', [], $user);
        } finally {
            // No row should have been persisted; the gate runs first.
            $this->assertSame(0, RemoteCliRun::query()->count());
            $this->assertSame(0, SiteAuditEvent::query()->count());
        }
    }

    public function test_destructive_command_from_admin_is_allowed_and_dispatches_async(): void
    {
        Bus::fake();

        [$user, , , $site] = $this->makeMemberUserWithSite(role: 'admin');

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldNotReceive('runInlineBashWithOutputCallback');

        $result = $this->wp($executor)->run($site, 'db drop', ['--yes'], $user);

        $this->assertTrue($result->isQueued());

        $run = RemoteCliRun::query()->sole();
        $this->assertSame(RiskLevel::Destructive, $run->risk);
        $this->assertSame(RemoteCliRun::MODE_ASYNC, $run->mode);

        Bus::assertDispatched(RunRemoteCliInBackgroundJob::class,
            fn (RunRemoteCliInBackgroundJob $job): bool => $job->remoteCliRunId === $run->id);
    }

    public function test_system_run_with_no_user_bypasses_permission_gate(): void
    {
        // Scaffold pipelines apply hardening defaults without a user
        // attribution. The gate must permit these.
        Bus::fake();
        [, , , $site] = $this->makeMemberUserWithSite();

        $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
        $executor->shouldNotReceive('runInlineBashWithOutputCallback');

        $result = $this->wp($executor)->run($site, 'db drop', [], queuedBy: null);

        $this->assertTrue($result->isQueued());
        Bus::assertDispatched(RunRemoteCliInBackgroundJob::class);
    }
}
