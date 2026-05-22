<?php

declare(strict_types=1);

namespace Tests\Feature\Debug\RecordLivewireDispatchedJobTest;
use App\Listeners\RecordLivewireDispatchedJob;
use App\Listeners\UpdateDispatchedJobLifecycle;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task as TaskRunnerTask;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @return array{0: User, 1: Organization, 2: Server}
 */
function actor(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
    ]);

    return [$user, $org, $server];
}
test('job dispatched during a livewire request creates a task row', function () {
    [$user, , $server] = actor();
    $this->actingAs($user);

    // Pretend we're inside a Livewire HTTP cycle so the listener's
    // request()->is('livewire/update') guard returns true.
    $this->call('POST', '/livewire/update', [], [], [], ['HTTP_ACCEPT' => 'application/json']);

    $job = new class($server->id)
    {
        function __construct(string $serverId)
        {
            $this->serverId = $serverId;
        }
    };
    $event = new JobQueued(
        connectionName: 'redis',
        queue: 'default',
        id: 'job-uuid-1',
        job: $job,
        payload: '',
        delay: null,
    );

    app(RecordLivewireDispatchedJob::class)->handle($event);

    $row = TaskRunnerTask::query()->where('instance', 'job-uuid-1')->first();
    expect($row)->not->toBeNull();
    expect($row->status)->toBe(TaskStatus::Pending);
    expect($row->server_id)->toBe($server->id);
    expect((string) $row->created_by)->toBe((string) $user->id);
    expect($row->name)->toStartWith('job:');
    expect($row->options['connection'])->toBe('redis');
    expect($row->options['queue'])->toBe('default');
});
test('job dispatched outside livewire is ignored', function () {
    [$user] = actor();
    $this->actingAs($user);

    // No /livewire/update call — request()->is('livewire/update') is false.
    $job = new class {};

    $event = new JobQueued('redis', 'default', 'job-uuid-noop', $job, '', null);
    app(RecordLivewireDispatchedJob::class)->handle($event);

    expect(TaskRunnerTask::query()->where('instance', 'job-uuid-noop')->count())->toBe(0);
});
test('lifecycle listener flips status through running to finished', function () {
    [$user] = actor();

    $row = TaskRunnerTask::query()->create([
        'name' => 'job:Stub',
        'action' => 'dispatched_job',
        'status' => TaskStatus::Pending,
        'instance' => 'lifecycle-uuid',
        'created_by' => $user->id,
        'options' => ['job_class' => 'Stub'],
    ]);

    $job = makeQueueJob('lifecycle-uuid');

    app(UpdateDispatchedJobLifecycle::class)->handleProcessing(
        new JobProcessing('redis', $job)
    );
    expect($row->fresh()->status)->toBe(TaskStatus::Running);
    expect($row->fresh()->started_at)->not->toBeNull();

    app(UpdateDispatchedJobLifecycle::class)->handleProcessed(
        new JobProcessed('redis', $job)
    );
    $fresh = $row->fresh();
    expect($fresh->status)->toBe(TaskStatus::Finished);
    expect($fresh->exit_code)->toBe(0);
    expect($fresh->completed_at)->not->toBeNull();
});
test('lifecycle listener records failure with exception message', function () {
    [$user] = actor();

    TaskRunnerTask::query()->create([
        'name' => 'job:Stub',
        'action' => 'dispatched_job',
        'status' => TaskStatus::Pending,
        'instance' => 'failed-uuid',
        'created_by' => $user->id,
        'options' => ['job_class' => 'Stub'],
    ]);

    $job = makeQueueJob('failed-uuid');
    $event = new JobFailed('redis', $job, new \RuntimeException('boom'));

    app(UpdateDispatchedJobLifecycle::class)->handleFailed($event);

    $fresh = TaskRunnerTask::query()->where('instance', 'failed-uuid')->first();
    expect($fresh->status)->toBe(TaskStatus::Failed);
    expect($fresh->exit_code)->toBe(1);
    $this->assertStringContainsString('boom', (string) $fresh->output);
});
test('lifecycle listener only touches dispatched job rows', function () {
    [$user] = actor();

    // A pre-existing TaskRunnerTask that did NOT come from a job
    // (action != 'dispatched_job'). The lifecycle listener must not
    // overwrite its status even if instance happens to collide.
    $row = TaskRunnerTask::query()->create([
        'name' => 'real-task',
        'action' => 'real_run',
        'status' => TaskStatus::Pending,
        'instance' => 'collide-uuid',
        'created_by' => $user->id,
    ]);

    $job = makeQueueJob('collide-uuid');
    app(UpdateDispatchedJobLifecycle::class)->handleProcessing(new JobProcessing('redis', $job));

    expect($row->fresh()->status)->toBe(TaskStatus::Pending);
});
/**
 * The listener only calls `method_exists($job, 'uuid') && $job->uuid()`,
 * so a thin object with a uuid() method is enough — no need to satisfy
 * the full Illuminate\Contracts\Queue\Job interface.
 */
function makeQueueJob(string $uuid): object
{
    return new class($uuid)
    {
        function __construct(private string $uuid)
        {
        }

        function uuid(): string
        {
            return $this->uuid;
        }
    };
}
