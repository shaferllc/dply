<?php

declare(strict_types=1);

namespace Tests\Feature\Debug;

use App\Listeners\RecordLivewireDispatchedJob;
use App\Listeners\UpdateDispatchedJobLifecycle;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task as TaskRunnerTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Tests\TestCase;

class RecordLivewireDispatchedJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Organization, 2: Server}
     */
    private function actor(): array
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

    public function test_job_dispatched_during_a_livewire_request_creates_a_task_row(): void
    {
        [$user, , $server] = $this->actor();
        $this->actingAs($user);

        // Pretend we're inside a Livewire HTTP cycle so the listener's
        // request()->is('livewire/update') guard returns true.
        $this->call('POST', '/livewire/update', [], [], [], ['HTTP_ACCEPT' => 'application/json']);

        $job = new class($server->id)
        {
            public string $serverId;

            public function __construct(string $serverId)
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
        $this->assertNotNull($row);
        $this->assertSame(TaskStatus::Pending, $row->status);
        $this->assertSame($server->id, $row->server_id);
        $this->assertSame((string) $user->id, (string) $row->created_by);
        $this->assertStringStartsWith('job:', $row->name);
        $this->assertSame('redis', $row->options['connection']);
        $this->assertSame('default', $row->options['queue']);
    }

    public function test_job_dispatched_outside_livewire_is_ignored(): void
    {
        [$user] = $this->actor();
        $this->actingAs($user);

        // No /livewire/update call — request()->is('livewire/update') is false.
        $job = new class {};

        $event = new JobQueued('redis', 'default', 'job-uuid-noop', $job, '', null);
        app(RecordLivewireDispatchedJob::class)->handle($event);

        $this->assertSame(0, TaskRunnerTask::query()->where('instance', 'job-uuid-noop')->count());
    }

    public function test_lifecycle_listener_flips_status_through_running_to_finished(): void
    {
        [$user] = $this->actor();

        $row = TaskRunnerTask::query()->create([
            'name' => 'job:Stub',
            'action' => 'dispatched_job',
            'status' => TaskStatus::Pending,
            'instance' => 'lifecycle-uuid',
            'created_by' => $user->id,
            'options' => ['job_class' => 'Stub'],
        ]);

        $job = $this->makeQueueJob('lifecycle-uuid');

        app(UpdateDispatchedJobLifecycle::class)->handleProcessing(
            new JobProcessing('redis', $job)
        );
        $this->assertSame(TaskStatus::Running, $row->fresh()->status);
        $this->assertNotNull($row->fresh()->started_at);

        app(UpdateDispatchedJobLifecycle::class)->handleProcessed(
            new JobProcessed('redis', $job)
        );
        $fresh = $row->fresh();
        $this->assertSame(TaskStatus::Finished, $fresh->status);
        $this->assertSame(0, $fresh->exit_code);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_lifecycle_listener_records_failure_with_exception_message(): void
    {
        [$user] = $this->actor();

        TaskRunnerTask::query()->create([
            'name' => 'job:Stub',
            'action' => 'dispatched_job',
            'status' => TaskStatus::Pending,
            'instance' => 'failed-uuid',
            'created_by' => $user->id,
            'options' => ['job_class' => 'Stub'],
        ]);

        $job = $this->makeQueueJob('failed-uuid');
        $event = new JobFailed('redis', $job, new \RuntimeException('boom'));

        app(UpdateDispatchedJobLifecycle::class)->handleFailed($event);

        $fresh = TaskRunnerTask::query()->where('instance', 'failed-uuid')->first();
        $this->assertSame(TaskStatus::Failed, $fresh->status);
        $this->assertSame(1, $fresh->exit_code);
        $this->assertStringContainsString('boom', (string) $fresh->output);
    }

    public function test_lifecycle_listener_only_touches_dispatched_job_rows(): void
    {
        [$user] = $this->actor();

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

        $job = $this->makeQueueJob('collide-uuid');
        app(UpdateDispatchedJobLifecycle::class)->handleProcessing(new JobProcessing('redis', $job));

        $this->assertSame(TaskStatus::Pending, $row->fresh()->status);
    }

    /**
     * The listener only calls `method_exists($job, 'uuid') && $job->uuid()`,
     * so a thin object with a uuid() method is enough — no need to satisfy
     * the full Illuminate\Contracts\Queue\Job interface.
     */
    private function makeQueueJob(string $uuid): object
    {
        return new class($uuid)
        {
            public function __construct(private string $uuid) {}

            public function uuid(): string
            {
                return $this->uuid;
            }
        };
    }
}
