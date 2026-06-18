<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Sites\LaravelConsoleExecutorAuditTest;

use App\Models\Organization;
use App\Models\RemoteCliRun;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Models\User;
use App\Modules\RemoteCli\Services\Kind;
use App\Modules\RemoteCli\Services\RiskLevel;
use App\Services\Sites\LaravelConsoleExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use ReflectionMethod;

uses(RefreshDatabase::class);

function makeLaravelSite(): Site
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
function callRecordRun(LaravelConsoleExecutor $executor, Site $site, string $command, array $args, RiskLevel $risk, int $exitCode, string $output): void
{
    $reflection = new ReflectionMethod($executor, 'recordRun');
    $reflection->invoke($executor, $site, $command, $args, $risk, $exitCode, $output, now()->subSecond());
}
test('successful run writes remote cli run with completed status', function () {
    $site = makeLaravelSite();
    $executor = app(LaravelConsoleExecutor::class);

    callRecordRun($executor, $site, 'migrate:status', [], RiskLevel::Read, 0, "Migration table created\nNo migrations.");

    $run = RemoteCliRun::query()->sole();
    expect($run->kind)->toBe(Kind::Artisan);
    expect($run->command)->toBe('migrate:status');
    expect($run->risk)->toBe(RiskLevel::Read);
    expect($run->status)->toBe('completed');
    expect($run->exit_code)->toBe(0);
    $this->assertStringContainsString('No migrations.', (string) $run->stdout);
});
test('failed run marks remote cli run failed', function () {
    $site = makeLaravelSite();
    $executor = app(LaravelConsoleExecutor::class);

    callRecordRun($executor, $site, 'migrate', [], RiskLevel::MutatingRecoverable, 1, 'SQLSTATE[42S01]: Base table or view already exists');

    $run = RemoteCliRun::query()->sole();
    expect($run->status)->toBe('failed');
    expect($run->exit_code)->toBe(1);
});
test('read commands do not emit audit events', function () {
    $site = makeLaravelSite();
    $executor = app(LaravelConsoleExecutor::class);

    callRecordRun($executor, $site, 'route:list', [], RiskLevel::Read, 0, '...');

    expect(RemoteCliRun::query()->count())->toBe(1, 'A run row is always written so the history surface picks up reads too');
    expect(SiteAuditEvent::query()->count())->toBe(0, 'Read commands never write audit rows — too many, no investigative value');
});
test('mutating recoverable commands emit audit event', function () {
    $site = makeLaravelSite();
    $executor = app(LaravelConsoleExecutor::class);

    callRecordRun($executor, $site, 'migrate', ['--force'], RiskLevel::MutatingRecoverable, 0, 'Migrating: ...');

    $event = SiteAuditEvent::query()->sole();
    expect($event->action)->toBe('artisan_run');
    expect($event->risk)->toBe(RiskLevel::MutatingRecoverable);
    expect($event->transport)->toBe(SiteAuditEvent::TRANSPORT_WEB);
    expect($event->result_status)->toBe(SiteAuditEvent::RESULT_SUCCESS);
    expect($event->payload['command'])->toBe('migrate');
    expect($event->payload['args'])->toBe(['--force']);
    expect($event->payload['remote_cli_run_id'])->not->toBeNull();
});
test('destructive command failure audits with failure status', function () {
    $site = makeLaravelSite();
    $executor = app(LaravelConsoleExecutor::class);

    callRecordRun($executor, $site, 'migrate:rollback', [], RiskLevel::Destructive, 2, 'Rollback failed: ...');

    $event = SiteAuditEvent::query()->where('action', 'artisan_run')->sole();
    expect($event->risk)->toBe(RiskLevel::Destructive);
    expect($event->result_status)->toBe(SiteAuditEvent::RESULT_FAILURE);
});
test('command verb extraction handles various argv shapes', function () {
    $executor = app(LaravelConsoleExecutor::class);
    $reflection = new ReflectionMethod($executor, 'commandVerb');

    expect($reflection->invoke($executor, 'migrate:status'))->toBe('migrate:status');
    expect($reflection->invoke($executor, 'migrate:rollback --step=1 --force'))->toBe('migrate:rollback');
    expect($reflection->invoke($executor, '   cache:clear  '))->toBe('cache:clear');
    expect($reflection->invoke($executor, ''))->toBe('');
});
