<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests\Commands;

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\TaskDispatcher;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Clear any existing tasks
    Task::query()->delete();
});

describe('TaskRunCommand', function () {
    it('runs a task by ID successfully', function () {
        $task = Task::factory()->create([
            'name' => 'Test Task',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: Test Task')
            ->expectsOutputToContain('Task completed successfully');
    });

    it('runs a task by name successfully', function () {
        $task = Task::factory()->create([
            'name' => 'Unique Task Name',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', ['name' => 'Unique Task Name'])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: Unique Task Name')
            ->expectsOutputToContain('Task completed successfully');
    });

    it('shows error when task not found by ID', function () {
        $this->artisan('task:run', ['id' => 99999])
            ->assertExitCode(1)
            ->expectsOutputToContain('Task not found');
    });

    it('shows error when task not found by name', function () {
        $this->artisan('task:run', ['name' => 'Non-existent Task'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Task not found');
    });

    it('shows error when multiple tasks found by name', function () {
        Task::factory()->count(2)->create([
            'name' => 'Duplicate Task Name',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', ['name' => 'Duplicate Task Name'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Multiple tasks found with name: Duplicate Task Name');
    });

    it('runs task in background when --background flag is used', function () {
        Queue::fake();

        $task = Task::factory()->create([
            'name' => 'Background Task',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', [
            'id' => $task->id,
            '--background' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task queued for background execution');
    });

    it('runs task with custom timeout when --timeout is specified', function () {
        $task = Task::factory()->create([
            'name' => 'Timeout Task',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', [
            'id' => $task->id,
            '--timeout' => 300,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: Timeout Task');
    });

    it('runs task with custom user when --user is specified', function () {
        $task = Task::factory()->create([
            'name' => 'User Task',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', [
            'id' => $task->id,
            '--user' => 'testuser',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: User Task');
    });

    it('runs task with custom instance when --instance is specified', function () {
        $task = Task::factory()->create([
            'name' => 'Instance Task',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', [
            'id' => $task->id,
            '--instance' => 'test-instance',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: Instance Task');
    });

    it('runs task with options when --option is specified', function () {
        $task = Task::factory()->create([
            'name' => 'Options Task',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', [
            'id' => $task->id,
            '--option' => ['key1=value1', 'key2=value2'],
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: Options Task');
    });

    it('shows verbose output when --verbose flag is used', function () {
        $task = Task::factory()->create([
            'name' => 'Verbose Task',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', [
            'id' => $task->id,
            '--verbose' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: Verbose Task');
    });

    it('shows dry run when --dry-run flag is used', function () {
        $task = Task::factory()->create([
            'name' => 'Dry Run Task',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', [
            'id' => $task->id,
            '--dry-run' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Dry run mode - task would be executed')
            ->expectsOutputToContain('Task: Dry Run Task');
    });

    it('waits for task completion when --wait flag is used', function () {
        $task = Task::factory()->create([
            'name' => 'Wait Task',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', [
            'id' => $task->id,
            '--wait' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: Wait Task');
    });

    it('shows task output when --show-output flag is used', function () {
        $task = Task::factory()->create([
            'name' => 'Output Task',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', [
            'id' => $task->id,
            '--show-output' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: Output Task');
    });

    it('handles task execution failure gracefully', function () {
        $task = Task::factory()->create([
            'name' => 'Failing Task',
            'status' => TaskStatus::Pending,
        ]);

        // Mock the TaskDispatcher to return a failed result
        $this->app->bind(TaskDispatcher::class, function () {
            return new class
            {
                public function run($pendingTask)
                {
                    return new class
                    {
                        public function isSuccessful()
                        {
                            return false;
                        }

                        public function getBuffer()
                        {
                            return 'Task failed with error';
                        }

                        public function getExitCode()
                        {
                            return 1;
                        }
                    };
                }
            };
        });

        $this->artisan('task:run', ['id' => $task->id])
            ->assertExitCode(1)
            ->expectsOutputToContain('Task execution failed');
    });

    it('handles task timeout gracefully', function () {
        $task = Task::factory()->create([
            'name' => 'Timeout Task',
            'status' => TaskStatus::Pending,
        ]);

        // Mock the TaskDispatcher to return a timeout result
        $this->app->bind(TaskDispatcher::class, function () {
            return new class
            {
                public function run($pendingTask)
                {
                    return new class
                    {
                        public function isSuccessful()
                        {
                            return false;
                        }

                        public function getBuffer()
                        {
                            return 'Task timed out';
                        }

                        public function getExitCode()
                        {
                            return 124; // Timeout exit code
                        }
                    };
                }
            };
        });

        $this->artisan('task:run', ['id' => $task->id])
            ->assertExitCode(1)
            ->expectsOutputToContain('Task execution failed');
    });

    it('validates timeout value', function () {
        $task = Task::factory()->create([
            'name' => 'Invalid Timeout Task',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', [
            'id' => $task->id,
            '--timeout' => -1,
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('Timeout must be a positive integer');
    });

    it('validates user value', function () {
        $task = Task::factory()->create([
            'name' => 'Invalid User Task',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', [
            'id' => $task->id,
            '--user' => '',
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('User cannot be empty');
    });

    it('validates instance value', function () {
        $task = Task::factory()->create([
            'name' => 'Invalid Instance Task',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', [
            'id' => $task->id,
            '--instance' => '',
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('Instance cannot be empty');
    });

    it('parses options correctly', function () {
        $task = Task::factory()->create([
            'name' => 'Options Parse Task',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', [
            'id' => $task->id,
            '--option' => ['key1=value1', 'key2=value2', 'key3=value with spaces'],
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: Options Parse Task');
    });

    it('handles invalid option format', function () {
        $task = Task::factory()->create([
            'name' => 'Invalid Options Task',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', [
            'id' => $task->id,
            '--option' => ['invalid-option'],
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('Invalid option format: invalid-option');
    });

    it('shows help when no arguments provided', function () {
        $this->artisan('task:run')
            ->assertExitCode(1)
            ->expectsOutputToContain('Not enough arguments');
    });

    it('shows help when --help flag is used', function () {
        $this->artisan('task:run', ['--help' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Run a task by ID or name');
    });

    it('handles task that is already running', function () {
        $task = Task::factory()->create([
            'name' => 'Running Task',
            'status' => TaskStatus::Running,
        ]);

        $this->artisan('task:run', ['id' => $task->id])
            ->assertExitCode(1)
            ->expectsOutputToContain('Task is already running');
    });

    it('handles task that is already finished', function () {
        $task = Task::factory()->create([
            'name' => 'Finished Task',
            'status' => TaskStatus::Finished,
        ]);

        $this->artisan('task:run', ['id' => $task->id])
            ->assertExitCode(1)
            ->expectsOutputToContain('Task is already finished');
    });

    it('handles task that has failed', function () {
        $task = Task::factory()->create([
            'name' => 'Failed Task',
            'status' => TaskStatus::Failed,
        ]);

        $this->artisan('task:run', ['id' => $task->id])
            ->assertExitCode(1)
            ->expectsOutputToContain('Task has failed');
    });

    it('allows running failed task with --force flag', function () {
        $task = Task::factory()->create([
            'name' => 'Force Task',
            'status' => TaskStatus::Failed,
        ]);

        $this->artisan('task:run', [
            'id' => $task->id,
            '--force' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: Force Task');
    });

    it('allows running finished task with --force flag', function () {
        $task = Task::factory()->create([
            'name' => 'Force Finished Task',
            'status' => TaskStatus::Finished,
        ]);

        $this->artisan('task:run', [
            'id' => $task->id,
            '--force' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: Force Finished Task');
    });

    it('shows task information in dry run mode', function () {
        $task = Task::factory()->create([
            'name' => 'Dry Run Info Task',
            'status' => TaskStatus::Pending,
            'timeout' => 300,
        ]);

        $this->artisan('task:run', [
            'id' => $task->id,
            '--dry-run' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task: Dry Run Info Task')
            ->expectsOutputToContain('Status: pending')
            ->expectsOutputToContain('Timeout: 300');
    });

    it('handles task with no timeout set', function () {
        $task = Task::factory()->create([
            'name' => 'No Timeout Task',
            'status' => TaskStatus::Pending,
            'timeout' => null,
        ]);

        $this->artisan('task:run', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: No Timeout Task');
    });

    it('handles task with no user set', function () {
        $task = Task::factory()->create([
            'name' => 'No User Task',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: No User Task');
    });

    it('handles task with no instance set', function () {
        $task = Task::factory()->create([
            'name' => 'No Instance Task',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: No Instance Task');
    });

    it('handles task with no options set', function () {
        $task = Task::factory()->create([
            'name' => 'No Options Task',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: No Options Task');
    });

    it('shows task output in verbose mode', function () {
        $task = Task::factory()->create([
            'name' => 'Verbose Output Task',
            'status' => TaskStatus::Pending,
        ]);

        // Mock the TaskDispatcher to return output
        $this->app->bind(TaskDispatcher::class, function () {
            return new class
            {
                public function run($pendingTask)
                {
                    return new class
                    {
                        public function isSuccessful()
                        {
                            return true;
                        }

                        public function getBuffer()
                        {
                            return "Line 1\nLine 2\nLine 3";
                        }

                        public function getExitCode()
                        {
                            return 0;
                        }
                    };
                }
            };
        });

        $this->artisan('task:run', [
            'id' => $task->id,
            '--verbose' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: Verbose Output Task')
            ->expectsOutputToContain('Line 1')
            ->expectsOutputToContain('Line 2')
            ->expectsOutputToContain('Line 3');
    });

    it('handles task with special characters in name', function () {
        $task = Task::factory()->create([
            'name' => 'Task with Special Chars !@#$%^&*()',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: Task with Special Chars !@#$%^&*()');
    });

    it('handles task with unicode characters in name', function () {
        $task = Task::factory()->create([
            'name' => 'Task with Unicode こんにちは世界',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: Task with Unicode こんにちは世界');
    });

    it('handles task with very long name', function () {
        $longName = str_repeat('A', 1000);
        $task = Task::factory()->create([
            'name' => $longName,
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: '.$longName);
    });

    it('handles task with empty name', function () {
        $task = Task::factory()->create([
            'name' => '',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: ');
    });

    it('handles task with null name', function () {
        $task = Task::factory()->create([
            'name' => null,
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:run', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running task: ');
    });
});
