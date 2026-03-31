<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\ProcessOutput;
use App\Modules\TaskRunner\Services\CallbackService;
use App\Modules\TaskRunner\Services\TaskRunnerService;
use App\Modules\TaskRunner\TaskDispatcher;
use App\Modules\TaskRunner\Traits\HandlesCallbacks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class TestWebhookCallbackHandler
{
    use HandlesCallbacks;

    public ?string $callbackUrl = 'https://example.test/callback';

    public bool $callbacksEnabled = true;
}

class TaskRunnerCancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_remote_task_cancellation_stops_process_and_marks_task_cancelled(): void
    {
        $server = Server::factory()->create([
            'ssh_private_key' => file_get_contents(base_path('app/TaskRunner/Tests/fixtures/private_key.pem')),
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

        $this->assertTrue($result['success']);
        $this->assertSame(TaskStatus::Cancelled, $task->status);
        $this->assertNotNull($task->completed_at);
    }

    public function test_cancelled_tasks_ignore_late_webhook_updates(): void
    {
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

        $this->assertSame(TaskStatus::Cancelled, $task->status);
        $this->assertSame('Task cancelled by user', $task->output);
    }

    public function test_finished_webhook_does_not_send_recursive_callback(): void
    {
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

        $this->assertSame(TaskStatus::Finished, $task->status);
        $this->assertSame(0, $task->exit_code);
        $this->assertNotNull($task->completed_at);
    }
}
