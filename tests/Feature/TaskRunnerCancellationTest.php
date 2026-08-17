<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RedirectGuestsToComingSoon;
use App\Models\Server;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Jobs\KillRemoteTaskProcessJob;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\TaskRunner\Services\CallbackService;
use App\Modules\TaskRunner\Services\TaskRunnerService;
use App\Modules\TaskRunner\TaskDispatcher;
use App\Modules\TaskRunner\Traits\HandlesCallbacks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

class TestWebhookCallbackHandler
{
    use HandlesCallbacks;

    public function __construct()
    {
        // Configure via the trait's API rather than redeclaring its protected
        // $callbackUrl/$callbacksEnabled properties — redeclaring them with a
        // different visibility/default is a fatal trait-composition collision.
        $this->setCallbackConfig([
            'url' => 'https://example.test/callback',
            'enabled' => true,
        ]);
    }
}

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Webhook routes are guest-accessible — bypass the coming-soon
    // middleware so the controller can run.
    $this->withoutMiddleware([RedirectGuestsToComingSoon::class]);
});

test('remote task cancellation stops process and marks task cancelled', function (): void {
    $server = Server::factory()->create([
        'ssh_private_key' => file_get_contents(base_path('app/Modules/TaskRunner/Tests/fixtures/private_key.pem')),
    ]);

    $task = Task::query()->create([
        'name' => 'Remote cancellation test',
        'action' => 'provision_stack',
        'server_id' => $server->id,
        'status' => TaskStatus::Running,
        'options' => [
            'remote_wrapper_script_path' => '/root/.dply-task-runner/task-cancel.sh',
            'remote_script_path' => '/root/.dply-task-runner/task-cancel-original.sh',
            'remote_pid_path' => '/root/.dply-task-runner/task-cancel.pid',
            'remote_child_pid_path' => '/root/.dply-task-runner/task-cancel-child.pid',
        ],
    ]);

    $dispatcher = \Mockery::mock(TaskDispatcher::class);
    $dispatcher->shouldReceive('run')
        ->once()
        ->andReturn(new ProcessOutput('cancelled remote task', 0, true));

    $this->app->instance(TaskDispatcher::class, $dispatcher);

    $result = $this->app->make(TaskRunnerService::class)->cancelTask($task->id);

    $task->refresh();

    expect($result['success'])->toBeTrue()
        ->and($task->status)->toBe(TaskStatus::Cancelled)
        ->and($task->completed_at)->not->toBeNull();

    // cancelTask marks the task locally and hands the SSH kill to a queue job so
    // the request does not block on stdio. Feature tests run with Queue::fake()
    // (tests/Concerns/FakesRemoteServerAccess), so the job is captured rather
    // than run inline — execute it here to keep asserting that the cancellation
    // actually reaches the dispatcher, which is what this test is about.
    Queue::assertPushed(KillRemoteTaskProcessJob::class, function (KillRemoteTaskProcessJob $job) use ($task): bool {
        return $job->taskId === (string) $task->id;
    });

    $pushed = collect(Queue::pushedJobs()[KillRemoteTaskProcessJob::class])->first()['job'];
    $pushed->handle($dispatcher);
});

test('cancelled tasks ignore late webhook updates', function (): void {
    $task = Task::query()->create([
        'name' => 'Cancelled task webhook test',
        'action' => 'test',
        'status' => TaskStatus::Cancelled,
        'output' => 'Task cancelled by user',
        'completed_at' => now(),
    ]);

    $finishedUrl = URL::signedRoute('webhook.task.mark-as-finished', ['task' => $task->id]);
    $failedUrl = URL::signedRoute('webhook.task.mark-as-failed', ['task' => $task->id]);
    $updateOutputUrl = URL::signedRoute('webhook.task.update-output', ['task' => $task->id]);

    $this->postJson($finishedUrl, ['exit_code' => 0])->assertOk();
    $this->postJson($failedUrl, ['exit_code' => 1])->assertOk();
    $this->postJson($updateOutputUrl, ['output' => 'late output', 'append_newline' => true])->assertOk();

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Cancelled)
        ->and($task->output)->toBe('Task cancelled by user');
});

test('finished webhook does not send recursive callback', function (): void {
    $callbackService = \Mockery::mock(CallbackService::class);
    $callbackService->shouldNotReceive('send');
    $this->app->instance(CallbackService::class, $callbackService);

    $task = Task::query()->create([
        'name' => 'Finished webhook recursion test',
        'action' => 'test',
        'status' => TaskStatus::Running,
        'instance' => Task::storeInstance(new TestWebhookCallbackHandler),
    ]);

    $finishedUrl = URL::signedRoute('webhook.task.mark-as-finished', ['task' => $task->id]);

    $this->postJson($finishedUrl, ['exit_code' => 0])->assertOk();

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Finished)
        ->and($task->exit_code)->toBe(0)
        ->and($task->completed_at)->not->toBeNull();
});
