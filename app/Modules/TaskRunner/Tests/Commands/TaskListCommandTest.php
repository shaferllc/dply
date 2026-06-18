<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Clear any existing tasks
    Task::query()->delete();
})->skip();

describe('TaskListCommand', function () {

    it('lists all tasks in table format by default', function () {
        // Create test tasks using factory
        Task::factory()->create([
            'name' => 'Test Task 1',
            'status' => TaskStatus::Finished,
            'exit_code' => 0,
        ]);

        Task::factory()->create([
            'name' => 'Test Task 2',
            'status' => TaskStatus::Running,
        ]);

        $this->artisan('task:list')
            ->assertExitCode(0)
            ->expectsOutputToContain('Test Task 1')
            ->expectsOutputToContain('Test Task 2')
            ->expectsOutputToContain('Finished')
            ->expectsOutputToContain('Running');
    });

    it('shows no tasks message when no tasks exist', function () {
        $this->artisan('task:list')
            ->assertExitCode(0)
            ->expectsOutput('No tasks found.');
    });

    it('filters tasks by name', function () {
        Task::factory()->create(['name' => 'Backup Task']);
        Task::factory()->create(['name' => 'Deploy Task']);
        Task::factory()->create(['name' => 'Cleanup Task']);

        $this->artisan('task:list', ['--name' => 'Backup'])
            ->assertExitCode(0)
            ->expectsOutputToContain('Backup Task')
            ->doesntExpectOutputToContain('Deploy Task')
            ->doesntExpectOutputToContain('Cleanup Task');
    });

    it('filters tasks by status', function () {
        Task::factory()->create(['status' => TaskStatus::Pending]);
        Task::factory()->create(['status' => TaskStatus::Running]);
        Task::factory()->create(['status' => TaskStatus::Finished]);

        $this->artisan('task:list', ['--status' => 'running'])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running')
            ->doesntExpectOutputToContain('Pending')
            ->doesntExpectOutputToContain('Finished');
    });

    it('filters tasks by running status using --running flag', function () {
        Task::factory()->create(['status' => TaskStatus::Pending]);
        Task::factory()->create(['status' => TaskStatus::Running]);
        Task::factory()->create(['status' => TaskStatus::Finished]);

        $this->artisan('task:list', ['--running' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Running')
            ->doesntExpectOutputToContain('Pending')
            ->doesntExpectOutputToContain('Finished');
    });

    it('filters tasks by failed status using --failed flag', function () {
        Task::factory()->create(['status' => TaskStatus::Finished]);
        Task::factory()->create(['status' => TaskStatus::Failed]);
        Task::factory()->create(['status' => TaskStatus::Timeout]);

        $this->artisan('task:list', ['--failed' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Failed')
            ->expectsOutputToContain('Timeout')
            ->doesntExpectOutputToContain('Finished');
    });

    it('filters tasks by recent tasks using --recent flag', function () {
        $recentTask = Task::factory()->create([
            'created_at' => now()->subHour(),
        ]);

        $oldTask = Task::factory()->create([
            'created_at' => now()->subDays(2),
        ]);

        $this->artisan('task:list', ['--recent' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain($recentTask->name)
            ->doesntExpectOutputToContain($oldTask->name);
    });

    it('limits the number of tasks shown', function () {
        Task::factory()->count(5)->create();

        $this->artisan('task:list', ['--limit' => 3])
            ->assertExitCode(0);

        // Count the lines in the output to verify limit
        $output = Artisan::output();
        $lines = explode("\n", trim($output));

        // Header + 3 data rows + summary = 5 lines
        expect(count($lines))->toBeLessThanOrEqual(5);
    });

    it('outputs tasks in JSON format', function () {
        $task = Task::factory()->create([
            'name' => 'JSON Test Task',
            'status' => TaskStatus::Finished,
            'exit_code' => 0,
        ]);

        $this->artisan('task:list', ['--format' => 'json'])
            ->assertExitCode(0);

        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data)->toBeArray();
        expect($data[0])->toHaveKey('id');
        expect($data[0])->toHaveKey('name');
        expect($data[0])->toHaveKey('status');
        expect($data[0]['name'])->toBe('JSON Test Task');
        expect($data[0]['status'])->toBe('finished');
    });

    it('outputs tasks in CSV format', function () {
        $task = Task::factory()->create([
            'name' => 'CSV Test Task',
            'status' => TaskStatus::Running,
        ]);

        $this->artisan('task:list', ['--format' => 'csv'])
            ->assertExitCode(0);

        $output = Artisan::output();
        $lines = explode("\n", trim($output));

        expect($lines[0])->toContain('ID,Name,Status,Created,Duration,Exit Code');
        expect($lines[1])->toContain('CSV Test Task');
        expect($lines[1])->toContain('running');
    });

    it('shows verbose information when --verbose flag is used', function () {
        $task = Task::factory()->create([
            'name' => 'Verbose Test Task',
            'status' => TaskStatus::Finished,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(2),
            'progress' => 100,
            'error' => 'Test error message',
        ]);

        $this->artisan('task:list', ['--detailed' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Started')
            ->expectsOutputToContain('Completed')
            ->expectsOutputToContain('Progress')
            ->expectsOutputToContain('Error');
    });

    it('shows verbose information in JSON format', function () {
        $task = Task::factory()->create([
            'name' => 'Verbose JSON Task',
            'status' => TaskStatus::Failed,
            'started_at' => now()->subMinutes(10),
            'completed_at' => now()->subMinutes(5),
            'progress' => 75,
            'error' => 'Task failed due to timeout',
            'output' => 'Some output content',
        ]);

        $this->artisan('task:list', ['--format' => 'json', '--detailed' => true])
            ->assertExitCode(0);

        $output = Artisan::output();
        $data = json_decode($output, true);

        expect($data[0])->toHaveKey('started_at');
        expect($data[0])->toHaveKey('completed_at');
        expect($data[0])->toHaveKey('progress');
        expect($data[0])->toHaveKey('error');
        expect($data[0])->toHaveKey('output');
        expect($data[0]['progress'])->toBe(75);
        expect($data[0]['error'])->toBe('Task failed due to timeout');
    });

    it('shows verbose information in CSV format', function () {
        $task = Task::factory()->create([
            'name' => 'Verbose CSV Task',
            'status' => TaskStatus::Timeout,
            'started_at' => now()->subMinutes(15),
            'completed_at' => now()->subMinutes(10),
            'progress' => 50,
            'error' => 'Task timed out',
        ]);

        $this->artisan('task:list', ['--format' => 'csv', '--detailed' => true])
            ->assertExitCode(0);

        $output = Artisan::output();
        $lines = explode("\n", trim($output));

        expect($lines[0])->toContain('Started');
        expect($lines[0])->toContain('Completed');
        expect($lines[0])->toContain('Progress');
        expect($lines[0])->toContain('Error');
        expect($lines[1])->toContain('Verbose CSV Task');
    });

    it('handles tasks with null values gracefully', function () {
        $task = Task::factory()->create([
            'name' => 'Null Values Task',
            'status' => TaskStatus::Pending,
            'exit_code' => null,
            'started_at' => null,
            'completed_at' => null,
            'progress' => null,
            'error' => null,
        ]);

        $this->artisan('task:list', ['--detailed' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Null Values Task')
            ->expectsOutputToContain('Pending');
    });

    it('formats status with colors correctly', function () {
        $pendingTask = Task::factory()->create(['status' => TaskStatus::Pending]);
        $runningTask = Task::factory()->create(['status' => TaskStatus::Running]);
        $finishedTask = Task::factory()->create(['status' => TaskStatus::Finished]);
        $failedTask = Task::factory()->create(['status' => TaskStatus::Failed]);

        $this->artisan('task:list')
            ->assertExitCode(0)
            ->expectsOutputToContain('<fg=yellow>Pending</>')
            ->expectsOutputToContain('<fg=blue>Running</>')
            ->expectsOutputToContain('<fg=green>Finished</>')
            ->expectsOutputToContain('<fg=red>Failed</>');
    });

    it('handles all task statuses correctly', function () {
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
            Task::factory()->create(['status' => $status]);
        }

        $this->artisan('task:list')
            ->assertExitCode(0);

        $output = Artisan::output();
        expect($output)->toContain('Pending');
        expect($output)->toContain('Running');
        expect($output)->toContain('Finished');
        expect($output)->toContain('Failed');
        expect($output)->toContain('Timeout');
        expect($output)->toContain('Cancelled');
        expect($output)->toContain('Upload Failed');
        expect($output)->toContain('Connection Failed');
    });

    it('calculates and displays duration correctly', function () {
        $task = Task::factory()->create([
            'name' => 'Duration Test Task',
            'status' => TaskStatus::Finished,
            'started_at' => now()->subMinutes(2),
            'completed_at' => now()->subMinute(),
        ]);

        $this->artisan('task:list')
            ->assertExitCode(0)
            ->expectsOutputToContain('Duration Test Task')
            ->expectsOutputToContain('60.00s'); // 1 minute = 60 seconds
    });

    it('shows dash for duration when task has not started', function () {
        $task = Task::factory()->create([
            'name' => 'No Duration Task',
            'status' => TaskStatus::Pending,
            'started_at' => null,
            'completed_at' => null,
        ]);

        $this->artisan('task:list')
            ->assertExitCode(0)
            ->expectsOutputToContain('No Duration Task')
            ->expectsOutputToContain('-');
    });

    it('handles multiple filters simultaneously', function () {
        // Create tasks with different characteristics
        Task::factory()->create([
            'name' => 'Backup Task',
            'status' => TaskStatus::Finished,
            'created_at' => now()->subHours(2),
        ]);

        Task::factory()->create([
            'name' => 'Backup Task Old',
            'status' => TaskStatus::Finished,
            'created_at' => now()->subDays(2),
        ]);

        Task::factory()->create([
            'name' => 'Deploy Task',
            'status' => TaskStatus::Running,
            'created_at' => now()->subHour(),
        ]);

        // Filter by name and recent
        $this->artisan('task:list', [
            '--name' => 'Backup',
            '--recent' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Backup Task')
            ->doesntExpectOutputToContain('Backup Task Old')
            ->doesntExpectOutputToContain('Deploy Task');
    });

    it('orders tasks by latest first', function () {
        $oldTask = Task::factory()->create([
            'name' => 'Old Task',
            'created_at' => now()->subDays(1),
        ]);

        $newTask = Task::factory()->create([
            'name' => 'New Task',
            'created_at' => now(),
        ]);

        $this->artisan('task:list')
            ->assertExitCode(0);

        $output = Artisan::output();
        $newTaskPosition = strpos($output, 'New Task');
        $oldTaskPosition = strpos($output, 'Old Task');

        expect($newTaskPosition)->toBeLessThan($oldTaskPosition);
    });

    it('handles CSV escaping correctly', function () {
        $task = Task::factory()->create([
            'name' => 'Task with "quotes" and commas, test',
            'status' => TaskStatus::Finished,
            'error' => 'Error with "quotes" and commas, test',
        ]);

        $this->artisan('task:list', ['--format' => 'csv', '--detailed' => true])
            ->assertExitCode(0);

        $output = Artisan::output();
        $lines = explode("\n", trim($output));

        // Check that quotes are properly escaped
        expect($lines[1])->toContain('"Task with ""quotes"" and commas, test"');
        expect($lines[1])->toContain('"Error with ""quotes"" and commas, test"');
    });

    it('handles invalid status filter gracefully', function () {
        Task::factory()->create(['status' => TaskStatus::Finished]);

        $this->artisan('task:list', ['--status' => 'invalid_status'])
            ->assertExitCode(0)
            ->expectsOutput('No tasks found.');
    });

    it('handles zero limit gracefully', function () {
        Task::factory()->count(3)->create();

        $this->artisan('task:list', ['--limit' => 0])
            ->assertExitCode(0)
            ->expectsOutput('No tasks found.');
    });

    it('handles negative limit gracefully', function () {
        Task::factory()->count(3)->create();

        $this->artisan('task:list', ['--limit' => -1])
            ->assertExitCode(0)
            ->expectsOutput('No tasks found.');
    });
});
