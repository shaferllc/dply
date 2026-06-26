<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\ServerCommandRunTest;

use App\Exceptions\ServerCommandNotPermittedException;
use App\Jobs\RunServerCommandJob;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerCommandRun;
use App\Models\User;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\ServerCommandRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;

uses(RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});

/**
 * @return array{0: User, 1: Organization, 2: Server}
 */
function makeServerActor(string $role = 'admin'): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    return [$user, $org, $server];
}

test('queue() blocks deployers and dispatches nothing', function () {
    Queue::fake();
    [$user, , $server] = makeServerActor('deployer');

    expect(fn () => app(ServerCommandRunner::class)->queue(
        server: $server,
        actor: $user,
        displayCommand: 'uname -a',
        remoteCommand: 'uname -a',
    ))->toThrow(ServerCommandNotPermittedException::class);

    expect(ServerCommandRun::query()->count())->toBe(0);
    Queue::assertNotPushed(RunServerCommandJob::class);
});

test('queue() persists a queued run and dispatches the worker on dply-control', function () {
    Queue::fake();
    [$user, , $server] = makeServerActor('admin');

    $run = app(ServerCommandRunner::class)->queue(
        server: $server,
        actor: $user,
        displayCommand: 'uname -a',
        remoteCommand: 'uname -a',
        source: ServerCommandRun::SOURCE_ADHOC,
    );

    expect($run->status)->toBe(ServerCommandRun::STATUS_QUEUED);
    expect($run->queued_by_user_id)->toBe($user->id);
    expect($run->source)->toBe(ServerCommandRun::SOURCE_ADHOC);

    Queue::assertPushed(RunServerCommandJob::class, function (RunServerCommandJob $job) use ($run) {
        return $job->serverCommandRunId === $run->id && $job->queue === 'dply-control';
    });
});

test('worker settles a completed run, captures output, and writes an audit log', function () {
    [$user, $org, $server] = makeServerActor('admin');

    $run = ServerCommandRun::query()->create([
        'server_id' => $server->id,
        'source' => ServerCommandRun::SOURCE_ADHOC,
        'command' => 'echo hi',
        'display_command' => 'echo hi',
        'status' => ServerCommandRun::STATUS_QUEUED,
        'queued_by_user_id' => $user->id,
    ]);

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runScriptWithOutputCallback')
        ->once()
        ->withArgs(function ($s, string $name, string $script, callable $cb, ?int $timeout) use ($server): bool {
            expect($s->id)->toBe($server->id);
            expect($script)->toContain('echo hi');
            // Simulate streamed stdout so the incremental flush path runs.
            $cb('out', 'hi'."\n");

            return true;
        })
        ->andReturn(new ProcessOutput("hi\n", 0, false));

    (new RunServerCommandJob($run->id))->handle($executor);

    $run->refresh();
    expect($run->status)->toBe(ServerCommandRun::STATUS_COMPLETED);
    expect($run->exit_code)->toBe(0);
    expect($run->stdout)->toContain('hi');
    expect($run->finished_at)->not->toBeNull();

    $audit = AuditLog::query()
        ->where('action', 'server.command.run')
        ->where('subject_type', Server::class)
        ->where('subject_id', $server->id)
        ->sole();
    expect($audit->organization_id)->toBe($org->id);
    expect($audit->user_id)->toBe($user->id);
    expect($audit->new_values['status'])->toBe(ServerCommandRun::STATUS_COMPLETED);
    expect($audit->new_values['exit_code'])->toBe(0);
});

test('worker marks a non-zero exit as failed', function () {
    [$user, , $server] = makeServerActor('admin');

    $run = ServerCommandRun::query()->create([
        'server_id' => $server->id,
        'source' => ServerCommandRun::SOURCE_ADHOC,
        'command' => 'exit 7',
        'display_command' => 'exit 7',
        'status' => ServerCommandRun::STATUS_QUEUED,
        'queued_by_user_id' => $user->id,
    ]);

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldReceive('runScriptWithOutputCallback')
        ->once()
        ->andReturn(new ProcessOutput('', 7, false));

    (new RunServerCommandJob($run->id))->handle($executor);

    $run->refresh();
    expect($run->status)->toBe(ServerCommandRun::STATUS_FAILED);
    expect($run->exit_code)->toBe(7);
});

test('worker is idempotent for already-settled rows', function () {
    [$user, , $server] = makeServerActor('admin');

    $run = ServerCommandRun::query()->create([
        'server_id' => $server->id,
        'source' => ServerCommandRun::SOURCE_ADHOC,
        'command' => 'echo hi',
        'display_command' => 'echo hi',
        'status' => ServerCommandRun::STATUS_COMPLETED,
        'queued_by_user_id' => $user->id,
    ]);

    $executor = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $executor->shouldNotReceive('runScriptWithOutputCallback');

    (new RunServerCommandJob($run->id))->handle($executor);

    expect(true)->toBeTrue();
});
