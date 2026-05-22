<?php

declare(strict_types=1);

namespace Tests\Feature\TaskRunner\ProvisionTaskCompletionTest;
use App\Jobs\CheckServerHealthJob;
use App\Jobs\DeployGuestMetricsCallbackEnvJob;
use App\Jobs\InstallMetricsAgentJob;
use App\Jobs\RunServerInsightsJob;
use App\Jobs\RunSetupScriptJob;
use App\Jobs\SyncServerSystemdServicesJob;
use App\Jobs\SyncServerSystemUsersJob;
use App\Models\Server;
use App\Models\ServerProvisionRun;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use Illuminate\Support\Facades\Bus;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // applyProvisionOutcomeToServer dispatches three follow-up jobs
    // on success (insights, health probe, metrics push pipeline)
    // that all attempt real SSH work. Fake them so the
    // observer-success path under test doesn't bomb on the
    // unreachable test fixture.
    Bus::fake([
        CheckServerHealthJob::class,
        DeployGuestMetricsCallbackEnvJob::class,
        InstallMetricsAgentJob::class,
        RunServerInsightsJob::class,
        SyncServerSystemdServicesJob::class,
        SyncServerSystemUsersJob::class,
    ]);
});
test('observer marks server done when provision task finishes', function () {
    $server = Server::factory()->create([
        'setup_status' => Server::SETUP_STATUS_RUNNING,
    ]);

    $task = Task::query()->create([
        'name' => 'Server stack provision',
        'action' => 'provision_stack',
        'status' => TaskStatus::Running,
        'server_id' => $server->id,
        'script' => 'x',
        'timeout' => 300,
        'user' => 'root',
    ]);

    $server->update([
        'meta' => array_merge($server->meta ?? [], ['provision_task_id' => (string) $task->id]),
    ]);

    $task->update([
        'status' => TaskStatus::Finished,
        'exit_code' => 0,
        'completed_at' => now(),
    ]);

    $server->refresh();
    expect($server->setup_status)->toBe(Server::SETUP_STATUS_DONE);
});
test('observer marks server failed when provision task fails', function () {
    $server = Server::factory()->create([
        'setup_status' => Server::SETUP_STATUS_RUNNING,
    ]);

    $task = Task::query()->create([
        'name' => 'Server stack provision',
        'action' => 'provision_stack',
        'status' => TaskStatus::Running,
        'server_id' => $server->id,
        'script' => 'x',
        'timeout' => 300,
        'user' => 'root',
    ]);

    $server->update([
        'meta' => array_merge($server->meta ?? [], ['provision_task_id' => (string) $task->id]),
    ]);

    $task->update([
        'status' => TaskStatus::Failed,
        'exit_code' => 1,
        'completed_at' => now(),
    ]);

    $server->refresh();
    expect($server->setup_status)->toBe(Server::SETUP_STATUS_FAILED);
});
test('observer persists per step output snapshots when provision output changes', function () {
    $server = Server::factory()->create([
        'setup_status' => Server::SETUP_STATUS_RUNNING,
    ]);

    $task = Task::query()->create([
        'name' => 'Server stack provision',
        'action' => 'provision_stack',
        'status' => TaskStatus::Running,
        'server_id' => $server->id,
        'script' => 'x',
        'script_content' => implode("\n", [
            "echo '[dply-step] Checking server status'",
            "echo '[dply-step] Creating server user'",
            "echo '[dply-step] Installing nginx'",
        ]),
        'timeout' => 300,
        'user' => 'root',
    ]);

    $server->update([
        'meta' => array_merge($server->meta ?? [], ['provision_task_id' => (string) $task->id]),
    ]);

    $task->update([
        'output' => implode("\n", [
            '[dply-step] Checking server status',
            'Server is reachable',
            '[dply-step] Creating server user',
            'Adding deploy user',
            'Granting sudo access',
            '[dply-step] Installing nginx',
            'Reading package lists',
        ]),
    ]);

    $server->refresh();

    $snapshots = $server->meta['provision_step_snapshots'] ?? [];

    expect($snapshots['script_'.md5('Checking server status')]['output'] ?? null)->toBe('Server is reachable');
    expect($snapshots['script_'.md5('Creating server user')]['output'] ?? null)->toBe(implode("\n", [
        'Adding deploy user',
        'Granting sudo access',
    ]));
    expect($snapshots['script_'.md5('Installing nginx')]['output'] ?? null)->toBe('Reading package lists');
});
test('observer persists verification and rollback artifacts for provision run', function () {
    $server = Server::factory()->create([
        'setup_status' => Server::SETUP_STATUS_RUNNING,
    ]);

    $task = Task::query()->create([
        'name' => 'Server stack provision',
        'action' => 'provision_stack',
        'status' => TaskStatus::Running,
        'server_id' => $server->id,
        'script' => 'x',
        'timeout' => 300,
        'user' => 'root',
    ]);

    $run = ServerProvisionRun::query()->create([
        'server_id' => $server->id,
        'task_id' => $task->id,
        'attempt' => 1,
        'status' => 'running',
        'started_at' => now(),
    ]);

    $server->update([
        'meta' => array_merge($server->meta ?? [], [
            'provision_task_id' => (string) $task->id,
            'provision_run_id' => (string) $run->id,
        ]),
    ]);

    $task->update([
        'output' => implode("\n", [
            '[dply-verify] nginx :: ok :: Check passed',
            '[dply-verify] php :: failed :: Check failed',
            '[dply-rollback] etc/nginx/sites-available/dply :: restored :: Previous config restored',
        ]),
        'status' => TaskStatus::Failed,
        'completed_at' => now(),
    ]);

    $run->refresh();

    expect($run->status)->toBe('failed');
    expect($run->rollback_status)->toBe('attempted');
    expect($run->artifacts()->where('type', 'verification_report')->first())->not->toBeNull();
    expect($run->artifacts()->where('type', 'rollback_report')->first())->not->toBeNull();
});
test('apply provision outcome sets deploy ssh user when key present', function () {
    $keyPath = base_path('app/TaskRunner/Tests/fixtures/private_key.pem');
    expect($keyPath)->toBeFile();

    $server = Server::factory()->create([
        'setup_status' => Server::SETUP_STATUS_RUNNING,
        'ssh_user' => 'root',
        'ssh_private_key' => file_get_contents($keyPath),
        'ssh_operational_private_key' => file_get_contents($keyPath),
    ]);

    expect($server->openSshPublicKeyFromPrivate())->not->toBeNull();

    RunSetupScriptJob::applyProvisionOutcomeToServer($server, true);

    $server->refresh();
    expect($server->setup_status)->toBe(Server::SETUP_STATUS_DONE);
    $deployUser = config('server_provision.deploy_ssh_user', 'dply');
    if ($deployUser !== '' && $deployUser !== 'root') {
        expect($server->ssh_user)->toBe($deployUser);
    }
});
test('apply provision outcome requires operational key before switching to deploy user', function () {
    $keyPath = base_path('app/TaskRunner/Tests/fixtures/private_key.pem');
    expect($keyPath)->toBeFile();

    $server = Server::factory()->create([
        'setup_status' => Server::SETUP_STATUS_RUNNING,
        'ssh_user' => 'root',
        'ssh_private_key' => file_get_contents($keyPath),
        'ssh_recovery_private_key' => file_get_contents($keyPath),
        'ssh_operational_private_key' => null,
    ]);

    RunSetupScriptJob::applyProvisionOutcomeToServer($server, true);

    $server->refresh();
    expect($server->setup_status)->toBe(Server::SETUP_STATUS_DONE);
    expect($server->ssh_user)->toBe('root');
});
