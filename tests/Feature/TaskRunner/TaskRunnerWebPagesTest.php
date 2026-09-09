<?php

declare(strict_types=1);

namespace Tests\Feature\TaskRunner\TaskRunnerWebPagesTest;

use App\Models\Organization;
use App\Models\User;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| The TaskRunner module's own web pages (app/Modules/TaskRunner/routes/web.php)
| are thin closures over near-vendored Blade views. They had no test at all, so
| a syntax error or a renamed view alias only surfaced by loading the page by
| hand. These render each route end to end.
*/

uses(RefreshDatabase::class);

/*
| The dashboard and the three monitor routes all render task-monitor.blade.php,
| which reads an $isRunning the TaskMonitor component never defines — so they
| 500 with "Undefined variable $isRunning". Deliberately left skipped rather
| than guessed at: the view uses it for a "Running / Stopped" indicator, and
| whether that means "auto-refresh is on" ($autoRefresh) or "a task is running"
| is a product decision, not a mechanical fix.
*/
const MONITOR_BROKEN = 'BROKEN IN PRODUCTION: livewire/task-monitor.blade.php reads $isRunning, '
    .'which App\\Modules\\TaskRunner\\Livewire\\TaskMonitor never declares — every route rendering '
    .'that component 500s. Decide whether the indicator should track $autoRefresh or real task '
    .'state, add the property, then un-skip.';

function taskRunnerUser(): User
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return $user;
}

test('task dashboard renders for an authenticated user', function () {
    $this->actingAs(taskRunnerUser())
        ->get(route('tasks.dashboard'))
        ->assertOk();
})->skip(MONITOR_BROKEN); // embeds the same task-monitor component

test('task execute page renders for an authenticated user', function () {
    $this->actingAs(taskRunnerUser())
        ->get(route('tasks.execute'))
        ->assertOk();
});

test('task list page renders for an authenticated user', function () {
    $this->actingAs(taskRunnerUser())
        ->get(route('tasks.list'))
        ->assertOk();
});

test('monitor page renders in all-tasks mode', function () {
    $this->actingAs(taskRunnerUser())
        ->get(route('tasks.monitor'))
        ->assertOk();
})->skip(MONITOR_BROKEN);

test('monitor page renders scoped to a task name', function () {
    $this->actingAs(taskRunnerUser())
        ->get(route('tasks.monitor.name', ['taskName' => 'provision_stack']))
        ->assertOk();
})->skip(MONITOR_BROKEN);

test('monitor page renders scoped to a task id', function () {
    $task = Task::query()->create([
        'name' => 'Monitored task',
        'action' => 'test',
        'status' => TaskStatus::Running,
    ]);

    $this->actingAs(taskRunnerUser())
        ->get(route('tasks.monitor.id', ['taskId' => $task->id]))
        ->assertOk();
})->skip(MONITOR_BROKEN);

test('task list renders with tasks in every status', function () {
    // Exercises the populated branches of the list view rather than its empty state.
    foreach ([TaskStatus::Running, TaskStatus::Finished, TaskStatus::Failed, TaskStatus::Cancelled] as $i => $status) {
        Task::query()->create([
            'name' => 'Task '.$i,
            'action' => 'test',
            'status' => $status,
            'output' => 'some output',
        ]);
    }

    $this->actingAs(taskRunnerUser())
        ->get(route('tasks.list'))
        ->assertOk();
});

test('task pages require authentication', function () {
    foreach (['tasks.dashboard', 'tasks.execute', 'tasks.list', 'tasks.monitor'] as $route) {
        $this->get(route($route))->assertRedirect();
    }
});
