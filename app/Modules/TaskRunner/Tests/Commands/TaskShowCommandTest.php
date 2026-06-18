<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests\Commands;

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Clear any existing tasks
    Task::query()->delete();
});

describe('TaskShowCommand', function () {
    it('shows task details by ID', function () {
        $task = Task::factory()->create([
            'name' => 'Test Task',
            'status' => TaskStatus::Finished,
            'exit_code' => 0,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(2),
            'output' => 'Task completed successfully',
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Test Task')
            ->expectsOutputToContain('Finished')
            ->expectsOutputToContain('Exit Code: 0')
            ->expectsOutputToContain('Task completed successfully');
    });

    it('shows task details by name', function () {
        $task = Task::factory()->create([
            'name' => 'Unique Task Name',
            'status' => TaskStatus::Running,
            'started_at' => now()->subMinutes(3),
        ]);

        $this->artisan('task:show', ['name' => 'Unique Task Name'])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Unique Task Name')
            ->expectsOutputToContain('Running');
    });

    it('shows error when task not found by ID', function () {
        $this->artisan('task:show', ['id' => 99999])
            ->assertExitCode(1)
            ->expectsOutputToContain('Task not found');
    });

    it('shows error when task not found by name', function () {
        $this->artisan('task:show', ['name' => 'Non-existent Task'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Task not found');
    });

    it('shows error when multiple tasks found by name', function () {
        Task::factory()->count(2)->create([
            'name' => 'Duplicate Task Name',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:show', ['name' => 'Duplicate Task Name'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Multiple tasks found with name: Duplicate Task Name');
    });

    it('shows task with all fields populated', function () {
        $task = Task::factory()->create([
            'name' => 'Complete Task',
            'status' => TaskStatus::Finished,
            'exit_code' => 0,
            'started_at' => now()->subMinutes(10),
            'completed_at' => now()->subMinutes(5),
            'output' => "Line 1\nLine 2\nLine 3",
            'error' => null,
            'progress' => 100,
            'timeout' => 300,
            'user' => 'testuser',
            'instance' => 'test-instance',
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Complete Task')
            ->expectsOutputToContain('Finished')
            ->expectsOutputToContain('Exit Code: 0')
            ->expectsOutputToContain('Progress: 100%')
            ->expectsOutputToContain('Timeout: 300')
            ->expectsOutputToContain('User: testuser')
            ->expectsOutputToContain('Instance: test-instance')
            ->expectsOutputToContain('Line 1')
            ->expectsOutputToContain('Line 2')
            ->expectsOutputToContain('Line 3');
    });

    it('shows task with null values gracefully', function () {
        $task = Task::factory()->create([
            'name' => 'Null Values Task',
            'status' => TaskStatus::Pending,
            'exit_code' => null,
            'started_at' => null,
            'completed_at' => null,
            'output' => null,
            'error' => null,
            'progress' => null,
            'timeout' => null,
            'user' => null,
            'instance' => null,
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Null Values Task')
            ->expectsOutputToContain('Pending')
            ->expectsOutputToContain('Exit Code: -')
            ->expectsOutputToContain('Progress: -')
            ->expectsOutputToContain('Timeout: -')
            ->expectsOutputToContain('User: -')
            ->expectsOutputToContain('Instance: -');
    });

    it('shows task with error information', function () {
        $task = Task::factory()->create([
            'name' => 'Error Task',
            'status' => TaskStatus::Failed,
            'exit_code' => 1,
            'error' => 'Task failed with error message',
            'output' => 'Some output before error',
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Error Task')
            ->expectsOutputToContain('Failed')
            ->expectsOutputToContain('Exit Code: 1')
            ->expectsOutputToContain('Error: Task failed with error message')
            ->expectsOutputToContain('Some output before error');
    });

    it('shows task with timeout status', function () {
        $task = Task::factory()->create([
            'name' => 'Timeout Task',
            'status' => TaskStatus::Timeout,
            'exit_code' => 124,
            'started_at' => now()->subMinutes(15),
            'completed_at' => now()->subMinutes(10),
            'timeout' => 300,
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Timeout Task')
            ->expectsOutputToContain('Timeout')
            ->expectsOutputToContain('Exit Code: 124')
            ->expectsOutputToContain('Timeout: 300');
    });

    it('shows task with cancelled status', function () {
        $task = Task::factory()->create([
            'name' => 'Cancelled Task',
            'status' => TaskStatus::Cancelled,
            'exit_code' => 130,
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Cancelled Task')
            ->expectsOutputToContain('Cancelled')
            ->expectsOutputToContain('Exit Code: 130');
    });

    it('shows task with upload failed status', function () {
        $task = Task::factory()->create([
            'name' => 'Upload Failed Task',
            'status' => TaskStatus::UploadFailed,
            'exit_code' => 2,
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Upload Failed Task')
            ->expectsOutputToContain('Upload Failed')
            ->expectsOutputToContain('Exit Code: 2');
    });

    it('shows task with connection failed status', function () {
        $task = Task::factory()->create([
            'name' => 'Connection Failed Task',
            'status' => TaskStatus::ConnectionFailed,
            'exit_code' => 3,
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Connection Failed Task')
            ->expectsOutputToContain('Connection Failed')
            ->expectsOutputToContain('Exit Code: 3');
    });

    it('shows task with partial progress', function () {
        $task = Task::factory()->create([
            'name' => 'Partial Progress Task',
            'status' => TaskStatus::Running,
            'progress' => 45,
            'started_at' => now()->subMinutes(5),
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Partial Progress Task')
            ->expectsOutputToContain('Running')
            ->expectsOutputToContain('Progress: 45%');
    });

    it('shows task with long output', function () {
        $longOutput = str_repeat("This is a long line of output.\n", 100);
        $task = Task::factory()->create([
            'name' => 'Long Output Task',
            'status' => TaskStatus::Finished,
            'output' => $longOutput,
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Long Output Task')
            ->expectsOutputToContain('This is a long line of output.');
    });

    it('shows task with special characters in output', function () {
        $specialOutput = "Output with special chars: !@#$%^&*()\nUnicode: こんにちは世界\n";
        $task = Task::factory()->create([
            'name' => 'Special Output Task',
            'status' => TaskStatus::Finished,
            'output' => $specialOutput,
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Special Output Task')
            ->expectsOutputToContain('Output with special chars: !@#$%^&*()')
            ->expectsOutputToContain('Unicode: こんにちは世界');
    });

    it('shows task with special characters in name', function () {
        $task = Task::factory()->create([
            'name' => 'Task with Special Chars !@#$%^&*()',
            'status' => TaskStatus::Finished,
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Task with Special Chars !@#$%^&*()');
    });

    it('shows task with unicode characters in name', function () {
        $task = Task::factory()->create([
            'name' => 'Task with Unicode こんにちは世界',
            'status' => TaskStatus::Finished,
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Task with Unicode こんにちは世界');
    });

    it('shows task with very long name', function () {
        $longName = str_repeat('A', 1000);
        $task = Task::factory()->create([
            'name' => $longName,
            'status' => TaskStatus::Finished,
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain($longName);
    });

    it('shows task with empty name', function () {
        $task = Task::factory()->create([
            'name' => '',
            'status' => TaskStatus::Finished,
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details');
    });

    it('shows task with null name', function () {
        $task = Task::factory()->create([
            'name' => null,
            'status' => TaskStatus::Finished,
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details');
    });

    it('shows task with empty output', function () {
        $task = Task::factory()->create([
            'name' => 'Empty Output Task',
            'status' => TaskStatus::Finished,
            'output' => '',
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Empty Output Task')
            ->expectsOutputToContain('No output available');
    });

    it('shows task with null output', function () {
        $task = Task::factory()->create([
            'name' => 'Null Output Task',
            'status' => TaskStatus::Finished,
            'output' => null,
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Null Output Task')
            ->expectsOutputToContain('No output available');
    });

    it('shows task with empty error', function () {
        $task = Task::factory()->create([
            'name' => 'Empty Error Task',
            'status' => TaskStatus::Failed,
            'error' => '',
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Empty Error Task')
            ->expectsOutputToContain('Failed');
    });

    it('shows task with null error', function () {
        $task = Task::factory()->create([
            'name' => 'Null Error Task',
            'status' => TaskStatus::Failed,
            'error' => null,
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Null Error Task')
            ->expectsOutputToContain('Failed');
    });

    it('shows task with zero progress', function () {
        $task = Task::factory()->create([
            'name' => 'Zero Progress Task',
            'status' => TaskStatus::Running,
            'progress' => 0,
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Zero Progress Task')
            ->expectsOutputToContain('Progress: 0%');
    });

    it('shows task with zero timeout', function () {
        $task = Task::factory()->create([
            'name' => 'Zero Timeout Task',
            'status' => TaskStatus::Finished,
            'timeout' => 0,
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Zero Timeout Task')
            ->expectsOutputToContain('Timeout: 0');
    });

    it('shows task with negative exit code', function () {
        $task = Task::factory()->create([
            'name' => 'Negative Exit Code Task',
            'status' => TaskStatus::Failed,
            'exit_code' => -1,
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Negative Exit Code Task')
            ->expectsOutputToContain('Exit Code: -1');
    });

    it('shows task with high exit code', function () {
        $task = Task::factory()->create([
            'name' => 'High Exit Code Task',
            'status' => TaskStatus::Failed,
            'exit_code' => 255,
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('High Exit Code Task')
            ->expectsOutputToContain('Exit Code: 255');
    });

    it('shows task with future start time', function () {
        $task = Task::factory()->create([
            'name' => 'Future Start Task',
            'status' => TaskStatus::Pending,
            'started_at' => now()->addMinutes(5),
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Future Start Task')
            ->expectsOutputToContain('Pending');
    });

    it('shows task with future completion time', function () {
        $task = Task::factory()->create([
            'name' => 'Future Completion Task',
            'status' => TaskStatus::Running,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now()->addMinutes(5),
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Future Completion Task')
            ->expectsOutputToContain('Running');
    });

    it('shows task with very old start time', function () {
        $task = Task::factory()->create([
            'name' => 'Old Start Task',
            'status' => TaskStatus::Finished,
            'started_at' => now()->subYears(1),
            'completed_at' => now()->subYears(1)->addMinutes(5),
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Old Start Task')
            ->expectsOutputToContain('Finished');
    });

    it('shows task with very old completion time', function () {
        $task = Task::factory()->create([
            'name' => 'Old Completion Task',
            'status' => TaskStatus::Finished,
            'started_at' => now()->subYears(1),
            'completed_at' => now()->subYears(1)->addMinutes(5),
        ]);

        $this->artisan('task:show', ['id' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Old Completion Task')
            ->expectsOutputToContain('Finished');
    });

    it('shows help when no arguments provided', function () {
        $this->artisan('task:show')
            ->assertExitCode(1)
            ->expectsOutputToContain('Not enough arguments');
    });

    it('shows help when --help flag is used', function () {
        $this->artisan('task:show', ['--help' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Show task details by ID or name');
    });

    it('shows task with all possible statuses', function () {
        $statuses = [
            TaskStatus::Pending,
            TaskStatus::Running,
            TaskStatus::Finished,
            TaskStatus::Failed,
            TaskStatus::Timeout,
            TaskStatus::Cancelled,
            TaskStatus::UploadFailed,
            TaskStatus::ConnectionFailed,
        ];

        foreach ($statuses as $status) {
            $task = Task::factory()->create([
                'name' => "Task with {$status->value} status",
                'status' => $status,
            ]);

            $this->artisan('task:show', ['id' => $task->id])
                ->assertExitCode(0)
                ->expectsOutputToContain('Task Details')
                ->expectsOutputToContain("Task with {$status->value} status");
        }
    });

    it('shows task with all possible exit codes', function () {
        $exitCodes = [0, 1, 2, 124, 125, 126, 127, 128, 130, 255];

        foreach ($exitCodes as $exitCode) {
            $task = Task::factory()->create([
                'name' => "Task with exit code {$exitCode}",
                'status' => TaskStatus::Finished,
                'exit_code' => $exitCode,
            ]);

            $this->artisan('task:show', ['id' => $task->id])
                ->assertExitCode(0)
                ->expectsOutputToContain('Task Details')
                ->expectsOutputToContain("Task with exit code {$exitCode}")
                ->expectsOutputToContain("Exit Code: {$exitCode}");
        }
    });

    it('shows task with all possible progress values', function () {
        $progressValues = [0, 25, 50, 75, 100];

        foreach ($progressValues as $progress) {
            $task = Task::factory()->create([
                'name' => "Task with {$progress}% progress",
                'status' => TaskStatus::Running,
                'progress' => $progress,
            ]);

            $this->artisan('task:show', ['id' => $task->id])
                ->assertExitCode(0)
                ->expectsOutputToContain('Task Details')
                ->expectsOutputToContain("Task with {$progress}% progress")
                ->expectsOutputToContain("Progress: {$progress}%");
        }
    });

    it('shows task with all possible timeout values', function () {
        $timeoutValues = [0, 60, 300, 600, 3600];

        foreach ($timeoutValues as $timeout) {
            $task = Task::factory()->create([
                'name' => "Task with {$timeout}s timeout",
                'status' => TaskStatus::Finished,
                'timeout' => $timeout,
            ]);

            $this->artisan('task:show', ['id' => $task->id])
                ->assertExitCode(0)
                ->expectsOutputToContain('Task Details')
                ->expectsOutputToContain("Task with {$timeout}s timeout")
                ->expectsOutputToContain("Timeout: {$timeout}");
        }
    });
});
