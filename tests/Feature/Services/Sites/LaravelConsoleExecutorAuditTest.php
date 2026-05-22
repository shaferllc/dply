<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Sites;

use App\Models\Organization;
use App\Models\RemoteCliRun;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Models\User;
use App\Services\RemoteCli\Kind;
use App\Services\RemoteCli\RiskLevel;
use App\Services\Sites\LaravelConsoleExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Verifies the audit-and-track wiring promoted onto LaravelConsoleExecutor:
 * every artisan invocation through the existing rich Custom commands tab
 * now writes a RemoteCliRun row + SiteAuditEvent for non-Read commands,
 * sharing the same surfaces as WordPressSection's wp-cli console + the
 * dply:artisan umbrella CLI.
 *
 * The execution mechanics (vm_ssh / local_docker / local_k8s dispatch)
 * are unchanged and covered by the existing LaravelConsoleExecutorTest;
 * this file focuses on the new recording layer via the private recordRun
 * method (reflection-accessed so we don't need to plumb the Docker / K8s
 * / SSH stack just to assert the audit output).
 */
class LaravelConsoleExecutorAuditTest extends TestCase
{
    use RefreshDatabase;

    private function makeLaravelSite(): Site
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'admin']);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        Auth::login($user);

        return Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'document_root' => '/home/dply/app/current',
            'meta' => ['vm_runtime' => ['detected' => ['framework' => 'laravel']]],
        ]);
    }

    private function callRecordRun(LaravelConsoleExecutor $executor, Site $site, string $command, array $args, RiskLevel $risk, int $exitCode, string $output): void
    {
        $reflection = new ReflectionMethod($executor, 'recordRun');
        $reflection->invoke($executor, $site, $command, $args, $risk, $exitCode, $output, now()->subSecond());
    }

    public function test_successful_run_writes_remote_cli_run_with_completed_status(): void
    {
        $site = $this->makeLaravelSite();
        $executor = app(LaravelConsoleExecutor::class);

        $this->callRecordRun($executor, $site, 'migrate:status', [], RiskLevel::Read, 0, "Migration table created\nNo migrations.");

        $run = RemoteCliRun::query()->sole();
        $this->assertSame(Kind::Artisan, $run->kind);
        $this->assertSame('migrate:status', $run->command);
        $this->assertSame(RiskLevel::Read, $run->risk);
        $this->assertSame('completed', $run->status);
        $this->assertSame(0, $run->exit_code);
        $this->assertStringContainsString('No migrations.', (string) $run->stdout);
    }

    public function test_failed_run_marks_remote_cli_run_failed(): void
    {
        $site = $this->makeLaravelSite();
        $executor = app(LaravelConsoleExecutor::class);

        $this->callRecordRun($executor, $site, 'migrate', [], RiskLevel::MutatingRecoverable, 1, 'SQLSTATE[42S01]: Base table or view already exists');

        $run = RemoteCliRun::query()->sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame(1, $run->exit_code);
    }

    public function test_read_commands_do_not_emit_audit_events(): void
    {
        $site = $this->makeLaravelSite();
        $executor = app(LaravelConsoleExecutor::class);

        $this->callRecordRun($executor, $site, 'route:list', [], RiskLevel::Read, 0, '...');

        $this->assertSame(1, RemoteCliRun::query()->count(),
            'A run row is always written so the history surface picks up reads too');
        $this->assertSame(0, SiteAuditEvent::query()->count(),
            'Read commands never write audit rows — too many, no investigative value');
    }

    public function test_mutating_recoverable_commands_emit_audit_event(): void
    {
        $site = $this->makeLaravelSite();
        $executor = app(LaravelConsoleExecutor::class);

        $this->callRecordRun($executor, $site, 'migrate', ['--force'], RiskLevel::MutatingRecoverable, 0, 'Migrating: ...');

        $event = SiteAuditEvent::query()->sole();
        $this->assertSame('artisan_run', $event->action);
        $this->assertSame(RiskLevel::MutatingRecoverable, $event->risk);
        $this->assertSame(SiteAuditEvent::TRANSPORT_WEB, $event->transport);
        $this->assertSame(SiteAuditEvent::RESULT_SUCCESS, $event->result_status);
        $this->assertSame('migrate', $event->payload['command']);
        $this->assertSame(['--force'], $event->payload['args']);
        $this->assertNotNull($event->payload['remote_cli_run_id']);
    }

    public function test_destructive_command_failure_audits_with_failure_status(): void
    {
        $site = $this->makeLaravelSite();
        $executor = app(LaravelConsoleExecutor::class);

        $this->callRecordRun($executor, $site, 'migrate:rollback', [], RiskLevel::Destructive, 2, 'Rollback failed: ...');

        $event = SiteAuditEvent::query()->where('action', 'artisan_run')->sole();
        $this->assertSame(RiskLevel::Destructive, $event->risk);
        $this->assertSame(SiteAuditEvent::RESULT_FAILURE, $event->result_status);
    }

    public function test_command_verb_extraction_handles_various_argv_shapes(): void
    {
        $executor = app(LaravelConsoleExecutor::class);
        $reflection = new ReflectionMethod($executor, 'commandVerb');

        $this->assertSame('migrate:status', $reflection->invoke($executor, 'migrate:status'));
        $this->assertSame('migrate:rollback', $reflection->invoke($executor, 'migrate:rollback --step=1 --force'));
        $this->assertSame('cache:clear', $reflection->invoke($executor, '   cache:clear  '));
        $this->assertSame('', $reflection->invoke($executor, ''));
    }
}
