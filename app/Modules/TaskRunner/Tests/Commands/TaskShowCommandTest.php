<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests\Commands;

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use Illuminate\Support\Facades\Artisan;
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

        $exitCode = Artisan::call('task:show', ['task' => $task->id, '--format' => 'json']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Test Task')
            ->and($output)->toContain('"status": "finished"')
            ->and($output)->toContain('"exit_code": 0')
            ->and($output)->toContain('Task completed successfully');
    });

    it('shows task details by name', function () {
        $task = Task::factory()->create([
            'name' => 'Unique Task Name',
            'status' => TaskStatus::Running,
            'started_at' => now()->subMinutes(3),
        ]);

        $this->artisan('task:show', ['task' => 'Unique Task Name'])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Unique Task Name')
            ->expectsOutputToContain('Running');
    });

    it('shows error when task not found by ID', function () {
        $this->artisan('task:show', ['task' => 99999])
            ->assertExitCode(1)
            ->expectsOutputToContain('Task not found');
    });

    it('shows error when task not found by name', function () {
        $this->artisan('task:show', ['task' => 'Non-existent Task'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Task not found');
    });

    it('shows error when multiple tasks found by name', function () {
        Task::factory()->count(2)->create([
            'name' => 'Duplicate Task Name',
            'status' => TaskStatus::Pending,
        ]);

        $this->artisan('task:show', ['task' => 'Duplicate Task Name'])
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
            'timeout' => 300,
            'user' => 'testuser',
            'instance' => 'test-instance',
            'options' => ['error' => null, 'progress' => 100],
        ]);

        $exitCode = Artisan::call('task:show', ['task' => $task->id, '--format' => 'json']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Complete Task')
            ->and($output)->toContain('"status": "finished"')
            ->and($output)->toContain('"exit_code": 0')
            ->and($output)->toContain('"progress": 100')
            ->and($output)->toContain('"timeout": 300')
            ->and($output)->toContain('User: testuser')
            ->and($output)->toContain('Instance: test-instance')
            ->and($output)->toContain('Line 1')
            ->and($output)->toContain('Line 2')
            ->and($output)->toContain('Line 3');
    });

    it('shows task with null values gracefully', function () {
        $task = Task::factory()->create([
            'name' => 'Null Values Task',
            'status' => TaskStatus::Pending,
            'exit_code' => null,
            'started_at' => null,
            'completed_at' => null,
            'output' => null,
            'timeout' => null,
            'user' => null,
            'instance' => null,
            'options' => ['error' => null, 'progress' => null],
        ]);

        $exitCode = Artisan::call('task:show', ['task' => $task->id, '--format' => 'json']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Null Values Task')
            ->and($output)->toContain('"status": "pending"')
            ->and($output)->toContain('"exit_code": -')
            ->and($output)->toContain('"progress": -')
            ->and($output)->toContain('"timeout": -')
            ->and($output)->toContain('User: -')
            ->and($output)->toContain('Instance: -');
    });

    it('shows task with error information', function () {
        $task = Task::factory()->create([
            'name' => 'Error Task',
            'status' => TaskStatus::Failed,
            'exit_code' => 1,
            'output' => 'Some output before error',
            'options' => ['error' => 'Task failed with error message'],
        ]);

        $exitCode = Artisan::call('task:show', ['task' => $task->id, '--format' => 'json']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Error Task')
            ->and($output)->toContain('"status": "failed"')
            ->and($output)->toContain('"exit_code": 1')
            ->and($output)->toContain('Error: Task failed with error message')
            ->and($output)->toContain('Some output before error');
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

        $exitCode = Artisan::call('task:show', ['task' => $task->id, '--format' => 'json']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Timeout Task')
            ->and($output)->toContain('"status": "timeout"')
            ->and($output)->toContain('"exit_code": 124')
            ->and($output)->toContain('"timeout": 300');
    });

    it('shows task with cancelled status', function () {
        $task = Task::factory()->create([
            'name' => 'Cancelled Task',
            'status' => TaskStatus::Cancelled,
            'exit_code' => 130,
        ]);

        $exitCode = Artisan::call('task:show', ['task' => $task->id, '--format' => 'json']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Cancelled Task')
            ->and($output)->toContain('"status": "cancelled"')
            ->and($output)->toContain('"exit_code": 130');
    });

    it('shows task with upload failed status', function () {
        $task = Task::factory()->create([
            'name' => 'Upload Failed Task',
            'status' => TaskStatus::UploadFailed,
            'exit_code' => 2,
        ]);

        $exitCode = Artisan::call('task:show', ['task' => $task->id, '--format' => 'json']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Upload Failed Task')
            ->and($output)->toContain('"status": "upload_failed"')
            ->and($output)->toContain('"exit_code": 2');
    });

    it('shows task with connection failed status', function () {
        $task = Task::factory()->create([
            'name' => 'Connection Failed Task',
            'status' => TaskStatus::ConnectionFailed,
            'exit_code' => 3,
        ]);

        $exitCode = Artisan::call('task:show', ['task' => $task->id, '--format' => 'json']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Connection Failed Task')
            ->and($output)->toContain('Connection Failed')
            ->and($output)->toContain('"exit_code": 3');
    });

    it('shows task with partial progress', function () {
        $task = Task::factory()->create([
            'name' => 'Partial Progress Task',
            'status' => TaskStatus::Running,
            'started_at' => now()->subMinutes(5),
            'options' => ['progress' => 45],
        ]);

        $exitCode = Artisan::call('task:show', ['task' => $task->id, '--format' => 'json']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Partial Progress Task')
            ->and($output)->toContain('"status": "running"')
            ->and($output)->toContain('"progress": 45');
    });

    it('shows task with long output', function () {
        $longOutput = str_repeat("This is a long line of output.\n", 100);
        $task = Task::factory()->create([
            'name' => 'Long Output Task',
            'status' => TaskStatus::Finished,
            'output' => $longOutput,
        ]);

        $this->artisan('task:show', ['task' => $task->id])
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

        $this->artisan('task:show', ['task' => $task->id])
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

        $this->artisan('task:show', ['task' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Task with Special Chars !@#$%^&*()');
    });

    it('shows task with unicode characters in name', function () {
        $task = Task::factory()->create([
            'name' => 'Task with Unicode こんにちは世界',
            'status' => TaskStatus::Finished,
        ]);

        $this->artisan('task:show', ['task' => $task->id])
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

        $this->artisan('task:show', ['task' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain($longName);
    });

    it('shows task with empty name', function () {
        $task = Task::factory()->create([
            'name' => '',
            'status' => TaskStatus::Finished,
        ]);

        $this->artisan('task:show', ['task' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details');
    });

    it('shows task with null name', function () {
        $task = Task::factory()->create([
            'name' => null,
            'status' => TaskStatus::Finished,
        ]);

        $this->artisan('task:show', ['task' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details');
    });

    it('shows task with empty output', function () {
        $task = Task::factory()->create([
            'name' => 'Empty Output Task',
            'status' => TaskStatus::Finished,
            'output' => '',
        ]);

        $this->artisan('task:show', ['task' => $task->id])
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

        $this->artisan('task:show', ['task' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Null Output Task')
            ->expectsOutputToContain('No output available');
    });

    it('shows task with empty error', function () {
        $task = Task::factory()->create([
            'name' => 'Empty Error Task',
            'status' => TaskStatus::Failed,
            'options' => ['error' => ''],
        ]);

        $this->artisan('task:show', ['task' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Empty Error Task')
            ->expectsOutputToContain('Failed');
    });

    it('shows task with null error', function () {
        $task = Task::factory()->create([
            'name' => 'Null Error Task',
            'status' => TaskStatus::Failed,
            'options' => ['error' => null],
        ]);

        $this->artisan('task:show', ['task' => $task->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('Task Details')
            ->expectsOutputToContain('Null Error Task')
            ->expectsOutputToContain('Failed');
    });

    it('shows task with zero progress', function () {
        $task = Task::factory()->create([
            'name' => 'Zero Progress Task',
            'status' => TaskStatus::Running,
            'options' => ['progress' => 0],
        ]);

        $exitCode = Artisan::call('task:show', ['task' => $task->id, '--format' => 'json']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Zero Progress Task')
            ->and($output)->toContain('"progress": 0');
    });

    it('shows task with zero timeout', function () {
        $task = Task::factory()->create([
            'name' => 'Zero Timeout Task',
            'status' => TaskStatus::Finished,
            'timeout' => 0,
        ]);

        $exitCode = Artisan::call('task:show', ['task' => $task->id, '--format' => 'json']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Zero Timeout Task')
            ->and($output)->toContain('"timeout": 0');
    });

    it('shows task with negative exit code', function () {
        $task = Task::factory()->create([
            'name' => 'Negative Exit Code Task',
            'status' => TaskStatus::Failed,
            'exit_code' => -1,
        ]);

        $exitCode = Artisan::call('task:show', ['task' => $task->id, '--format' => 'json']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Negative Exit Code Task')
            ->and($output)->toContain('"exit_code": -1');
    });

    it('shows task with high exit code', function () {
        $task = Task::factory()->create([
            'name' => 'High Exit Code Task',
            'status' => TaskStatus::Failed,
            'exit_code' => 255,
        ]);

        $exitCode = Artisan::call('task:show', ['task' => $task->id, '--format' => 'json']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('High Exit Code Task')
            ->and($output)->toContain('"exit_code": 255');
    });

    it('shows task with future start time', function () {
        $task = Task::factory()->create([
            'name' => 'Future Start Task',
            'status' => TaskStatus::Pending,
            'started_at' => now()->addMinutes(5),
        ]);

        $this->artisan('task:show', ['task' => $task->id])
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

        $this->artisan('task:show', ['task' => $task->id])
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

        $this->artisan('task:show', ['task' => $task->id])
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

        $this->artisan('task:show', ['task' => $task->id])
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

            $this->artisan('task:show', ['task' => $task->id])
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

            $this->artisan('task:show', ['task' => $task->id, '--format' => 'json'])
                ->assertExitCode(0)
                ->expectsOutputToContain("Task with exit code {$exitCode}")
                ->expectsOutputToContain('"exit_code": ');
        }
    });

    it('shows task with all possible progress values', function () {
        $progressValues = [0, 25, 50, 75, 100];

        foreach ($progressValues as $progress) {
            $task = Task::factory()->create([
                'name' => "Task with {$progress}% progress",
                'status' => TaskStatus::Running,
                'options' => ['progress' => $progress],
            ]);

            $this->artisan('task:show', ['task' => $task->id, '--format' => 'json'])
                ->assertExitCode(0)
                ->expectsOutputToContain("Task with {$progress}% progress")
                ->expectsOutputToContain('"progress": ');
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

            $this->artisan('task:show', ['task' => $task->id, '--format' => 'json'])
                ->assertExitCode(0)
                ->expectsOutputToContain("Task with {$timeout}s timeout")
                ->expectsOutputToContain('"timeout": ');
        }
    });
});
