<?php

declare(strict_types=1);

namespace Tests\Feature\TaskRunner\TaskRunnerWebhookTest;

use App\Http\Middleware\RedirectGuestsToComingSoon;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\Tests\Helpers\TestTask;
use App\Modules\TaskRunner\TrackTaskInBackground;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Webhook routes are guest-accessible — RedirectGuestsToComingSoon
    // catches them in non-local envs and returns 302 instead of letting
    // the controller's signature check run. Bypass for the whole class.
    $this->withoutMiddleware([RedirectGuestsToComingSoon::class]);
});
test('unsigned webhook update output is rejected', function () {
    $task = Task::query()->create([
        'name' => 'Webhook probe',
        'action' => 'probe',
        'status' => TaskStatus::Running,
        'output' => null,
    ]);

    $this->postJson('/webhook/task/update-output/'.$task->id, ['output' => 'x'])
        ->assertStatus(403);
});
test('signed webhook update output appends to task', function () {
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
});
test('signed webhook update output appends multiple chunks in order', function () {
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

    expect((string) $task->output)->toBe("existing\nfirst line\nsecond line\n");
});
test('signed webhook mark as finished returns success without task instance', function () {
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
    expect($task->status)->toBe(TaskStatus::Finished);
    expect($task->exit_code)->toBe(0);
    expect($task->completed_at)->not->toBeNull();

    Log::shouldHaveReceived('info')->with('Task finish webhook received', \Mockery::on(function (array $context) use ($task): bool {
        return $context['task_id'] === $task->id && $context['current_status'] === TaskStatus::Running->value;
    }))->once();
    Log::shouldHaveReceived('info')->with('Task webhook finalized task', \Mockery::on(function (array $context) use ($task): bool {
        return $context['task_id'] === $task->id && $context['status'] === TaskStatus::Finished->value && $context['exit_code'] === 0;
    }))->once();
});
test('signed webhook mark as finished updates tracked task status', function () {
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

    expect($task->status)->toBe(TaskStatus::Finished);
    expect($task->exit_code)->toBe(0);
    expect($task->completed_at)->not->toBeNull();
});
test('signed webhook mark as finished updates tracked task status with encoded instance', function () {
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

    expect($task->status)->toBe(TaskStatus::Finished);
    expect($task->exit_code)->toBe(0);
    expect($task->completed_at)->not->toBeNull();
});
test('signed webhook mark as finished finalizes task even with malformed instance', function () {
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

    expect($task->status)->toBe(TaskStatus::Finished);
    expect($task->exit_code)->toBe(0);
    expect($task->completed_at)->not->toBeNull();
});
test('signed webhook mark as finished logs skip for already terminal task', function () {
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
});
test('webhook url uses dply public app url when configured', function () {
    Config::set('app.url', 'http://127.0.0.1');
    Config::set('dply.public_app_url', 'https://tunnel.example.test');

    $task = Task::query()->create([
        'name' => 'URL probe',
        'action' => 'probe',
        'status' => TaskStatus::Running,
    ]);

    $url = $task->webhookUrl('updateOutput');

    expect($url)->toStartWith('https://tunnel.example.test/');
    $this->assertStringContainsString('/webhook/task/update-output/'.$task->id, $url);
});
