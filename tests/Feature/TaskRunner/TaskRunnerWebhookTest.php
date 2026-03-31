<?php

declare(strict_types=1);

namespace Tests\Feature\TaskRunner;

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
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

    public function test_signed_webhook_mark_as_finished_returns_success_without_task_instance(): void
    {
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
