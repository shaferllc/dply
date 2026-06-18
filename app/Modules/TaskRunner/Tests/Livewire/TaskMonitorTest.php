<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Livewire\TaskMonitor;
use App\Modules\TaskRunner\Models\Task;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('TaskMonitor Livewire Component', function () {
    it('can mount with default parameters', function () {
        Livewire::test(TaskMonitor::class)
            ->assertSet('taskName', null)
            ->assertSet('taskId', null)
            ->assertSet('showAllTasks', false)
            ->assertSet('autoRefresh', true)
            ->assertSet('refreshInterval', 2000);
    });

    it('can mount with specific task name', function () {
        Livewire::test(TaskMonitor::class, ['taskName' => 'test-task'])
            ->assertSet('taskName', 'test-task')
            ->assertSet('showAllTasks', false);
    });

    it('can mount with specific task ID', function () {
        Livewire::test(TaskMonitor::class, ['taskId' => 'task-123'])
            ->assertSet('taskId', 'task-123')
            ->assertSet('showAllTasks', false);
    });

    it('can mount with show all tasks flag', function () {
        Livewire::test(TaskMonitor::class, ['showAllTasks' => true])
            ->assertSet('showAllTasks', true);
    });

    it('loads tasks when showing all tasks', function () {
        // Create some test tasks
        Task::factory()->count(3)->create([
            'status' => TaskStatus::Finished,
        ]);

        Livewire::test(TaskMonitor::class, ['showAllTasks' => true])
            ->assertSet('showAllTasks', true)
            ->assertSet('tasks', function ($tasks) {
                return count($tasks) === 3;
            });
    });

    it('loads tasks by name', function () {
        Task::factory()->create(['name' => 'specific-task']);
        Task::factory()->create(['name' => 'other-task']);

        Livewire::test(TaskMonitor::class, ['taskName' => 'specific-task'])
            ->assertSet('tasks', function ($tasks) {
                return count($tasks) === 1 && $tasks[0]['name'] === 'specific-task';
            });
    });

    it('loads task by ID', function () {
        $task = Task::factory()->create();

        Livewire::test(TaskMonitor::class, ['taskId' => $task->id])
            ->assertSet('tasks', function ($tasks) use ($task) {
                return count($tasks) === 1 && $tasks[0]['id'] === $task->id;
            });
    });

    it('shows empty state when no tasks found', function () {
        Livewire::test(TaskMonitor::class, ['showAllTasks' => true])
            ->assertSet('tasks', [])
            ->assertSee('No tasks found');
    });

    it('can toggle auto refresh', function () {
        Livewire::test(TaskMonitor::class)
            ->assertSet('autoRefresh', true)
            ->call('toggleAutoRefresh')
            ->assertSet('autoRefresh', false)
            ->call('toggleAutoRefresh')
            ->assertSet('autoRefresh', true);
    });

    it('can refresh tasks manually', function () {
        $task = Task::factory()->create();

        Livewire::test(TaskMonitor::class, ['showAllTasks' => true])
            ->call('refreshTasks')
            ->assertSet('tasks', function ($tasks) use ($task) {
                return count($tasks) === 1 && $tasks[0]['id'] === $task->id;
            });
    });

    it('can clear output for specific task', function () {
        $task = Task::factory()->create([
            'output' => 'some output content',
        ]);

        Livewire::test(TaskMonitor::class, ['taskId' => $task->id])
            ->set('taskOutputs', [$task->id => 'some output'])
            ->call('clearOutput', $task->id)
            ->assertSet('taskOutputs', []);
    });

    it('can clear all outputs', function () {
        $task1 = Task::factory()->create();
        $task2 = Task::factory()->create();

        Livewire::test(TaskMonitor::class, ['showAllTasks' => true])
            ->set('taskOutputs', [
                $task1->id => 'output 1',
                $task2->id => 'output 2',
            ])
            ->set('taskErrors', [
                $task1->id => 'error 1',
                $task2->id => 'error 2',
            ])
            ->call('clearAllOutputs')
            ->assertSet('taskOutputs', [])
            ->assertSet('taskErrors', []);
    });

    it('handles task started event', function () {
        $task = Task::factory()->create(['status' => TaskStatus::Pending]);

        Livewire::test(TaskMonitor::class, ['showAllTasks' => true])
            ->call('handleTaskStarted', [
                'task' => $task,
                'taskId' => $task->id,
                'taskName' => $task->name,
            ])
            ->assertSet('tasks', function ($tasks) use ($task) {
                return count($tasks) === 1 && $tasks[0]['id'] === $task->id;
            });
    });

    it('handles task completed event', function () {
        $task = Task::factory()->create(['status' => TaskStatus::Running]);

        Livewire::test(TaskMonitor::class, ['showAllTasks' => true])
            ->call('handleTaskCompleted', [
                'task' => $task,
                'taskId' => $task->id,
                'result' => ['exit_code' => 0, 'output' => 'success'],
            ])
            ->assertSet('taskResults', function ($results) use ($task) {
                return isset($results[$task->id]) && $results[$task->id]['exit_code'] === 0;
            });
    });

    it('handles task failed event', function () {
        $task = Task::factory()->create(['status' => TaskStatus::Running]);

        Livewire::test(TaskMonitor::class, ['showAllTasks' => true])
            ->call('handleTaskFailed', [
                'task' => $task,
                'taskId' => $task->id,
                'error' => 'Task execution failed',
            ])
            ->assertSet('taskErrors', function ($errors) use ($task) {
                return isset($errors[$task->id]) && $errors[$task->id] === 'Task execution failed';
            });
    });

    it('handles task progress event', function () {
        $task = Task::factory()->create();

        Livewire::test(TaskMonitor::class, ['showAllTasks' => true])
            ->call('handleTaskProgress', [
                'taskId' => $task->id,
                'progress' => 50,
            ])
            ->assertSet('taskProgress', function ($progress) use ($task) {
                return isset($progress[$task->id]) && $progress[$task->id] === 50;
            });
    });

    it('handles task output event', function () {
        $task = Task::factory()->create();

        Livewire::test(TaskMonitor::class, ['showAllTasks' => true])
            ->call('handleTaskOutput', [
                'taskId' => $task->id,
                'output' => 'New output line',
            ])
            ->assertSet('taskOutputs', function ($outputs) use ($task) {
                return isset($outputs[$task->id]) && $outputs[$task->id] === 'New output line';
            });
    });

    it('handles task chain events', function () {
        $task = Task::factory()->create();

        Livewire::test(TaskMonitor::class, ['showAllTasks' => true])
            ->call('handleTaskChainStarted', [
                'chainId' => 'chain-123',
                'tasks' => [$task],
            ])
            ->call('handleTaskChainCompleted', [
                'chainId' => 'chain-123',
                'results' => ['success'],
            ])
            ->call('handleTaskChainFailed', [
                'chainId' => 'chain-123',
                'error' => 'Chain failed',
            ])
            ->call('handleTaskChainProgress', [
                'chainId' => 'chain-123',
                'progress' => 75,
            ]);

        // Should not throw any exceptions
        expect(true)->toBeTrue();
    });

    it('handles parallel task events', function () {
        $task = Task::factory()->create();

        Livewire::test(TaskMonitor::class, ['showAllTasks' => true])
            ->call('handleParallelTaskStarted', [
                'executionId' => 'parallel-123',
                'tasks' => [$task],
            ])
            ->call('handleParallelTaskCompleted', [
                'executionId' => 'parallel-123',
                'results' => ['success'],
            ])
            ->call('handleParallelTaskFailed', [
                'executionId' => 'parallel-123',
                'error' => 'Parallel execution failed',
            ]);

        // Should not throw any exceptions
        expect(true)->toBeTrue();
    });

    it('filters tasks based on tracking criteria', function () {
        $task1 = Task::factory()->create(['name' => 'tracked-task']);
        $task2 = Task::factory()->create(['name' => 'other-task']);

        Livewire::test(TaskMonitor::class, ['taskName' => 'tracked-task'])
            ->assertSet('tasks', function ($tasks) use ($task1) {
                return count($tasks) === 1 && $tasks[0]['id'] === $task1->id;
            });
    });

    it('formats task data for display', function () {
        $task = Task::factory()->create([
            'name' => 'Test Task',
            'status' => TaskStatus::Finished,
            'exit_code' => 0,
            'output' => 'Task completed successfully',
        ]);

        Livewire::test(TaskMonitor::class, ['taskId' => $task->id])
            ->assertSet('tasks', function ($tasks) {
                $formattedTask = $tasks[0];

                return $formattedTask['name'] === 'Test Task' &&
                       $formattedTask['status'] === TaskStatus::Finished->value &&
                       $formattedTask['exit_code'] === 0 &&
                       $formattedTask['output'] === 'Task completed successfully';
            });
    });

    it('renders the component view', function () {
        Livewire::test(TaskMonitor::class)
            ->assertViewIs('task-runner::livewire.task-monitor');
    });

    it('updates task status in real-time', function () {
        $task = Task::factory()->create(['status' => TaskStatus::Pending]);

        Livewire::test(TaskMonitor::class, ['showAllTasks' => true])
            ->assertSet('tasks', function ($tasks) {
                return $tasks[0]['status'] === TaskStatus::Pending->value;
            })
            ->call('handleTaskStarted', [
                'task' => $task,
                'taskId' => $task->id,
            ])
            ->assertSet('tasks', function ($tasks) {
                return $tasks[0]['status'] === TaskStatus::Running->value;
            });
    });

    it('accumulates task output', function () {
        $task = Task::factory()->create();

        Livewire::test(TaskMonitor::class, ['showAllTasks' => true])
            ->call('handleTaskOutput', [
                'taskId' => $task->id,
                'output' => 'Line 1',
            ])
            ->call('handleTaskOutput', [
                'taskId' => $task->id,
                'output' => 'Line 2',
            ])
            ->assertSet('taskOutputs', function ($outputs) use ($task) {
                return isset($outputs[$task->id]) &&
                       str_contains($outputs[$task->id], 'Line 1') &&
                       str_contains($outputs[$task->id], 'Line 2');
            });
    });

    it('handles multiple tasks simultaneously', function () {
        $task1 = Task::factory()->create(['name' => 'task-1']);
        $task2 = Task::factory()->create(['name' => 'task-2']);

        Livewire::test(TaskMonitor::class, ['showAllTasks' => true])
            ->call('handleTaskOutput', [
                'taskId' => $task1->id,
                'output' => 'Task 1 output',
            ])
            ->call('handleTaskOutput', [
                'taskId' => $task2->id,
                'output' => 'Task 2 output',
            ])
            ->assertSet('taskOutputs', function ($outputs) use ($task1, $task2) {
                return isset($outputs[$task1->id]) &&
                       isset($outputs[$task2->id]) &&
                       $outputs[$task1->id] === 'Task 1 output' &&
                       $outputs[$task2->id] === 'Task 2 output';
            });
    });
});
