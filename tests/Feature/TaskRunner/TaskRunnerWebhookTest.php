<?php

declare(strict_types=1);

namespace Tests\Feature\TaskRunner;

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\Tests\Helpers\TestTask;
use App\Modules\TaskRunner\TrackTaskInBackground;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class TaskRunnerWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsigned_webhook_update_output_is_rejected(): void
    {
        $task = Task::query()->create([
            'name' => 'Webhook probe',
            'action' => 'probe',
            'status' => TaskStatus::Running,
            'output' => null,
        ]);

        $this->postJson('/webhook/task/update-output/'.$task->id, ['output' => 'x'])
            ->assertStatus(403);
    }

    public function test_signed_webhook_update_output_appends_to_task(): void
    {
        $task = Task::query()->create([
            'name' => 'Webhook probe',
            'action' => 'probe',
            'status' => TaskStatus::Running,
            'output' => 'existing',
        ]);

        $url = URL::signedRoute('webhook.task.update-output', ['task' => $task->id]);

        $this->postJson($url, ['output' => "\nfrom remote\n"])
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $task->refresh();
        $this->assertStringContainsString('from remote', (string) $task->output);
    }

    public function test_signed_webhook_update_output_appends_multiple_chunks_in_order(): void
    {
        $task = Task::query()->create([
            'name' => 'Webhook probe',
            'action' => 'probe',
            'status' => TaskStatus::Running,
            'output' => "existing\n",
        ]);

        $url = URL::signedRoute('webhook.task.update-output', ['task' => $task->id]);

        $this->postJson($url, ['output' => 'first line', 'append_newline' => true])
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->postJson($url, ['output' => 'second line', 'append_newline' => true])
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $task->refresh();

        $this->assertSame("existing\nfirst line\nsecond line\n", (string) $task->output);
    }

    public function test_signed_webhook_mark_as_finished_returns_success_without_task_instance(): void
    {
        Log::spy();

        $task = Task::query()->create([
            'name' => 'Callback probe',
            'action' => 'probe',
            'status' => TaskStatus::Running,
            'instance' => null,
        ]);

        $url = URL::signedRoute('webhook.task.mark-as-finished', ['task' => $task->id]);

        $this->postJson($url, [])
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $task->refresh();
        $this->assertSame(TaskStatus::Finished, $task->status);
        $this->assertSame(0, $task->exit_code);
        $this->assertNotNull($task->completed_at);

        Log::shouldHaveReceived('info')->with('Task finish webhook received', \Mockery::on(function (array $context) use ($task): bool {
            return $context['task_id'] === $task->id && $context['current_status'] === TaskStatus::Running->value;
        }))->once();
        Log::shouldHaveReceived('info')->with('Task webhook finalized task', \Mockery::on(function (array $context) use ($task): bool {
            return $context['task_id'] === $task->id && $context['status'] === TaskStatus::Finished->value && $context['exit_code'] === 0;
        }))->once();
    }

    public function test_signed_webhook_mark_as_finished_updates_tracked_task_status(): void
    {
        $actualTask = new TestTask('Provision server');
        $trackedTask = new TrackTaskInBackground(
            $actualTask,
            'https://example.com/finished',
            'https://example.com/failed',
            'https://example.com/timeout',
        );

        $task = Task::query()->create([
            'name' => 'Provision server',
            'action' => 'provision_stack',
            'status' => TaskStatus::Running,
            'script' => 'wrapper.sh',
            'timeout' => 300,
            'user' => 'root',
        ]);

        $actualTask->setTaskModel($task);
        $trackedTask->setTaskModel($task);
        $task->update([
            'instance' => serialize($trackedTask),
        ]);

        $url = URL::signedRoute('webhook.task.mark-as-finished', ['task' => $task->id]);

        $this->postJson($url, ['exit_code' => 0])
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $task->refresh();

        $this->assertSame(TaskStatus::Finished, $task->status);
        $this->assertSame(0, $task->exit_code);
        $this->assertNotNull($task->completed_at);
    }

    public function test_signed_webhook_mark_as_finished_updates_tracked_task_status_with_encoded_instance(): void
    {
        $actualTask = new TestTask('Provision server');
        $trackedTask = new TrackTaskInBackground(
            $actualTask,
            'https://example.com/finished',
            'https://example.com/failed',
            'https://example.com/timeout',
        );

        $task = Task::query()->create([
            'name' => 'Provision server',
            'action' => 'provision_stack',
            'status' => TaskStatus::Running,
            'script' => 'wrapper.sh',
            'timeout' => 300,
            'user' => 'root',
        ]);

        $actualTask->setTaskModel($task);
        $trackedTask->setTaskModel($task);
        $task->update([
            'instance' => base64_encode(serialize($trackedTask)),
        ]);

        $url = URL::signedRoute('webhook.task.mark-as-finished', ['task' => $task->id]);

        $this->postJson($url, ['exit_code' => 0])
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $task->refresh();

        $this->assertSame(TaskStatus::Finished, $task->status);
        $this->assertSame(0, $task->exit_code);
        $this->assertNotNull($task->completed_at);
    }

    public function test_signed_webhook_mark_as_finished_finalizes_task_even_with_malformed_instance(): void
    {
        $task = Task::query()->create([
            'name' => 'Provision server',
            'action' => 'provision_stack',
            'status' => TaskStatus::Running,
            'script' => 'wrapper.sh',
            'timeout' => 300,
            'user' => 'root',
            'instance' => 'O:44:"App\Modules\TaskRunner\TrackTaskInBackground":12:{s:14:"',
        ]);

        $url = URL::signedRoute('webhook.task.mark-as-finished', ['task' => $task->id]);

        $this->postJson($url, ['exit_code' => 0])
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $task->refresh();

        $this->assertSame(TaskStatus::Finished, $task->status);
        $this->assertSame(0, $task->exit_code);
        $this->assertNotNull($task->completed_at);
    }

    public function test_signed_webhook_mark_as_finished_logs_skip_for_already_terminal_task(): void
    {
        Log::spy();

        $task = Task::query()->create([
            'name' => 'Finished task',
            'action' => 'probe',
            'status' => TaskStatus::Finished,
            'exit_code' => 0,
            'completed_at' => now(),
        ]);

        $url = URL::signedRoute('webhook.task.mark-as-finished', ['task' => $task->id]);

        $this->postJson($url, ['exit_code' => 0])
            ->assertOk()
            ->assertJson(['status' => 'success']);

        Log::shouldHaveReceived('info')->with('Task finish webhook received', \Mockery::on(function (array $context) use ($task): bool {
            return $context['task_id'] === $task->id && $context['current_status'] === TaskStatus::Finished->value;
        }))->once();
        Log::shouldHaveReceived('info')->with('Task webhook finalize skipped for terminal task', \Mockery::on(function (array $context) use ($task): bool {
            return $context['task_id'] === $task->id && $context['current_status'] === TaskStatus::Finished->value;
        }))->once();
    }

    public function test_webhook_url_uses_dply_public_app_url_when_configured(): void
    {
        Config::set('app.url', 'http://127.0.0.1');
        Config::set('dply.public_app_url', 'https://tunnel.example.test');

        $task = Task::query()->create([
            'name' => 'URL probe',
            'action' => 'probe',
            'status' => TaskStatus::Running,
        ]);

        $url = $task->webhookUrl('updateOutput');

        $this->assertStringStartsWith('https://tunnel.example.test/', $url);
        $this->assertStringContainsString('/webhook/task/update-output/'.$task->id, $url);
    }
}
