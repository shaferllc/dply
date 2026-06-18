<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use Tests\TestCase;

uses(TestCase::class);

describe('TaskController', function () {
    beforeEach(function () {
        // Create test user for authentication if needed
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    });

    describe('index method', function () {
        it('returns paginated tasks with default parameters', function () {
            // Create test tasks
            Task::factory()->count(5)->create();

            $response = $this->getJson('/api/tasks');

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'id', 'name', 'status', 'created_at', 'updated_at',
                        ],
                    ],
                    'pagination' => [
                        'current_page', 'last_page', 'per_page', 'total',
                    ],
                ]);
        });

        it('filters tasks by name', function () {
            Task::factory()->create(['name' => 'backup-task']);
            Task::factory()->create(['name' => 'deploy-task']);

            $response = $this->getJson('/api/tasks?name=backup');

            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(1);
            expect($response->json('data.0.name'))->toBe('backup-task');
        });

        it('filters tasks by status', function () {
            Task::factory()->create(['status' => TaskStatus::Running]);
            Task::factory()->create(['status' => TaskStatus::Finished]);

            $response = $this->getJson('/api/tasks?status=running');

            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(1);
            expect($response->json('data.0.status'))->toBe('running');
        });

        it('filters running tasks with boolean parameter', function () {
            Task::factory()->create(['status' => TaskStatus::Running]);
            Task::factory()->create(['status' => TaskStatus::Finished]);

            $response = $this->getJson('/api/tasks?running=true');

            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(1);
            expect($response->json('data.0.status'))->toBe('running');
        });

        it('filters failed tasks', function () {
            Task::factory()->create(['status' => TaskStatus::Failed]);
            Task::factory()->create(['status' => TaskStatus::Finished]);

            $response = $this->getJson('/api/tasks?failed=true');

            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(1);
            expect($response->json('data.0.status'))->toBe('failed');
        });

        it('filters recent tasks', function () {
            Task::factory()->create(['created_at' => now()->subHours(2)]);
            Task::factory()->create(['created_at' => now()->subHours(25)]);

            $response = $this->getJson('/api/tasks?recent=24');

            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(1);
        });

        it('applies custom sorting', function () {
            Task::factory()->create(['name' => 'A Task']);
            Task::factory()->create(['name' => 'B Task']);

            $response = $this->getJson('/api/tasks?sort_by=name&sort_order=asc');

            $response->assertStatus(200);
            expect($response->json('data.0.name'))->toBe('A Task');
            expect($response->json('data.1.name'))->toBe('B Task');
        });

        it('respects pagination limits', function () {
            Task::factory()->count(25)->create();

            $response = $this->getJson('/api/tasks?per_page=10');

            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(10);
            expect($response->json('pagination.per_page'))->toBe(10);
        });

        it('caps per_page at maximum of 100', function () {
            $response = $this->getJson('/api/tasks?per_page=150');

            $response->assertStatus(200);
            expect($response->json('pagination.per_page'))->toBe(100);
        });
    });

    describe('show method', function () {
        it('returns task by ID', function () {
            $task = Task::factory()->create();

            $response = $this->getJson("/api/tasks/{$task->id}");

            $response->assertStatus(200)
                ->assertJson([
                    'data' => [
                        'id' => $task->id,
                        'name' => $task->name,
                    ],
                ]);
        });

        it('returns task by name when ID not found', function () {
            $task = Task::factory()->create(['name' => 'unique-task-name']);

            $response = $this->getJson('/api/tasks/unique-task-name');

            $response->assertStatus(200)
                ->assertJson([
                    'data' => [
                        'id' => $task->id,
                        'name' => 'unique-task-name',
                    ],
                ]);
        });

        it('returns 404 when task not found', function () {
            $response = $this->getJson('/api/tasks/non-existent-task');

            $response->assertStatus(404)
                ->assertJson(['error' => 'Task not found']);
        });
    });

    describe('run method', function () {
        it('validates required command field', function () {
            $response = $this->postJson('/api/tasks/run', []);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['command']);
        });

        it('validates timeout is positive integer', function () {
            $response = $this->postJson('/api/tasks/run', [
                'command' => 'echo test',
                'timeout' => -1,
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['timeout']);
        });

        it('validates data field is array', function () {
            $response = $this->postJson('/api/tasks/run', [
                'command' => 'echo test',
                'data' => 'not-an-array',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['data']);
        });
    });

    describe('runParallel method', function () {
        it('validates required tasks array', function () {
            $response = $this->postJson('/api/tasks/run/parallel', []);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['tasks']);
        });

        it('validates tasks array is not empty', function () {
            $response = $this->postJson('/api/tasks/run/parallel', [
                'tasks' => [],
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['tasks']);
        });

        it('validates each task has required command', function () {
            $response = $this->postJson('/api/tasks/run/parallel', [
                'tasks' => [
                    ['name' => 'Task 1'], // Missing command
                ],
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['tasks.0.command']);
        });

        it('validates max_concurrency limits', function () {
            $response = $this->postJson('/api/tasks/run/parallel', [
                'tasks' => [
                    ['command' => 'echo test'],
                ],
                'max_concurrency' => 100, // Exceeds max of 50
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['max_concurrency']);
        });
    });

    describe('runChain method', function () {
        it('validates chain parameters', function () {
            $response = $this->postJson('/api/tasks/run/chain', [
                'tasks' => [],
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['tasks']);
        });

        it('validates max_concurrency limits', function () {
            $response = $this->postJson('/api/tasks/run/chain', [
                'tasks' => [
                    ['command' => 'echo test'],
                ],
                'max_concurrency' => 100, // Exceeds max of 50
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['max_concurrency']);
        });
    });

    describe('stream method', function () {
        it('returns task stream data', function () {
            $task = Task::factory()->create([
                'status' => TaskStatus::Running,
                'output' => 'Task output...',
                'progress' => 50,
            ]);

            $response = $this->getJson("/api/tasks/{$task->id}/stream");

            $response->assertStatus(200)
                ->assertJson([
                    'data' => [
                        'task_id' => $task->id,
                        'name' => $task->name,
                        'status' => 'running',
                        'output' => 'Task output...',
                        'progress' => 50,
                        'is_running' => true,
                    ],
                ]);
        });

        it('returns 404 for non-existent task', function () {
            $response = $this->getJson('/api/tasks/non-existent/stream');

            $response->assertStatus(404)
                ->assertJson(['error' => 'Task not found']);
        });

        it('finds task by name when ID not found', function () {
            $task = Task::factory()->create(['name' => 'stream-test-task']);

            $response = $this->getJson('/api/tasks/stream-test-task/stream');

            $response->assertStatus(200)
                ->assertJson([
                    'data' => [
                        'task_id' => $task->id,
                        'name' => 'stream-test-task',
                    ],
                ]);
        });
    });

    describe('cancel method', function () {
        it('cancels a running task', function () {
            $task = Task::factory()->create([
                'status' => TaskStatus::Running,
            ]);

            $response = $this->postJson("/api/tasks/{$task->id}/cancel");

            $response->assertStatus(200)
                ->assertJson([
                    'data' => [
                        'task_id' => $task->id,
                        'status' => 'cancelled',
                        'message' => 'Task cancelled successfully',
                    ],
                ]);

            $task->refresh();
            expect($task->status)->toBe(TaskStatus::Cancelled);
            expect($task->completed_at)->not->toBeNull();
        });

        it('returns 404 for non-existent task', function () {
            $response = $this->postJson('/api/tasks/non-existent/cancel');

            $response->assertStatus(404)
                ->assertJson(['error' => 'Task not found']);
        });

        it('returns error for non-active task', function () {
            $task = Task::factory()->create([
                'status' => TaskStatus::Finished,
            ]);

            $response = $this->postJson("/api/tasks/{$task->id}/cancel");

            $response->assertStatus(400)
                ->assertJson(['error' => 'Task is not running']);
        });

        it('finds task by name when ID not found', function () {
            $task = Task::factory()->create([
                'name' => 'cancel-test-task',
                'status' => TaskStatus::Running,
            ]);

            $response = $this->postJson('/api/tasks/cancel-test-task/cancel');

            $response->assertStatus(200)
                ->assertJson([
                    'data' => [
                        'task_id' => $task->id,
                        'status' => 'cancelled',
                    ],
                ]);
        });
    });

    describe('destroy method', function () {
        it('deletes a task', function () {
            $task = Task::factory()->create();

            $response = $this->deleteJson("/api/tasks/{$task->id}");

            $response->assertStatus(200)
                ->assertJson([
                    'data' => [
                        'message' => 'Task deleted successfully',
                    ],
                ]);

            $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        });

        it('returns 404 for non-existent task', function () {
            $response = $this->deleteJson('/api/tasks/non-existent');

            $response->assertStatus(404)
                ->assertJson(['error' => 'Task not found']);
        });

        it('finds task by name when ID not found', function () {
            $task = Task::factory()->create(['name' => 'delete-test-task']);

            $response = $this->deleteJson('/api/tasks/delete-test-task');

            $response->assertStatus(200);
            $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        });
    });

    describe('stats method', function () {
        it('returns task statistics', function () {
            // Create tasks with different statuses
            Task::factory()->count(3)->create(['status' => TaskStatus::Running]);
            Task::factory()->count(5)->create(['status' => TaskStatus::Finished]);
            Task::factory()->count(2)->create(['status' => TaskStatus::Failed]);
            Task::factory()->count(1)->create(['status' => TaskStatus::Pending]);

            $response = $this->getJson('/api/tasks/stats');

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'data' => [
                        'total', 'running', 'completed', 'failed', 'pending',
                        'recent_24h', 'recent_7d', 'avg_duration', 'success_rate',
                    ],
                ]);

            $data = $response->json('data');
            expect($data['total'])->toBe(11);
            expect($data['running'])->toBe(3);
            expect($data['completed'])->toBe(5);
            expect($data['failed'])->toBe(2);
            expect($data['pending'])->toBe(1);
        });

        it('calculates success rate correctly', function () {
            Task::factory()->count(8)->create(['status' => TaskStatus::Finished]);
            Task::factory()->count(2)->create(['status' => TaskStatus::Failed]);

            $response = $this->getJson('/api/tasks/stats');

            $data = $response->json('data');
            expect($data['success_rate'])->toBe(80); // 8/10 * 100
        });

        it('returns zero success rate when no completed tasks', function () {
            Task::factory()->count(3)->create(['status' => TaskStatus::Running]);

            $response = $this->getJson('/api/tasks/stats');

            $data = $response->json('data');
            expect($data['success_rate'])->toBe(0);
        });
    });

    describe('byStatus method', function () {
        it('returns tasks filtered by status', function () {
            Task::factory()->count(3)->create(['status' => TaskStatus::Running]);
            Task::factory()->count(2)->create(['status' => TaskStatus::Finished]);

            $response = $this->getJson('/api/tasks/status/running');

            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(3);
            expect($response->json('data.0.status'))->toBe('running');
        });

        it('returns 400 for invalid status', function () {
            $response = $this->getJson('/api/tasks/status/invalid-status');

            $response->assertStatus(400)
                ->assertJson(['error' => 'Invalid status']);
        });

        it('returns paginated results', function () {
            Task::factory()->count(25)->create(['status' => TaskStatus::Finished]);

            $response = $this->getJson('/api/tasks/status/finished');

            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(25);
            expect($response->json('pagination.per_page'))->toBe(50);
        });
    });

    describe('search method', function () {
        it('searches tasks by name', function () {
            Task::factory()->create(['name' => 'backup-database']);
            Task::factory()->create(['name' => 'deploy-application']);

            $response = $this->getJson('/api/tasks/search?q=backup');

            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(1);
            expect($response->json('data.0.name'))->toBe('backup-database');
        });

        it('searches tasks by output', function () {
            Task::factory()->create(['output' => 'Backup completed successfully']);
            Task::factory()->create(['output' => 'Deployment failed']);

            $response = $this->getJson('/api/tasks/search?q=completed');

            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(1);
            expect($response->json('data.0.output'))->toBe('Backup completed successfully');
        });

        it('searches tasks by error', function () {
            Task::factory()->create(['error' => 'Connection timeout']);
            Task::factory()->create(['error' => 'Permission denied']);

            $response = $this->getJson('/api/tasks/search?q=timeout');

            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(1);
            expect($response->json('data.0.error'))->toBe('Connection timeout');
        });

        it('returns 400 when query is missing', function () {
            $response = $this->getJson('/api/tasks/search');

            $response->assertStatus(400)
                ->assertJson(['error' => 'Search query required']);
        });

        it('returns empty results for no matches', function () {
            Task::factory()->create(['name' => 'existing-task']);

            $response = $this->getJson('/api/tasks/search?q=non-existent');

            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(0);
        });
    });

    describe('clearCompleted method', function () {
        it('clears completed tasks', function () {
            // Create tasks with different statuses
            Task::factory()->count(3)->create(['status' => TaskStatus::Finished]);
            Task::factory()->count(2)->create(['status' => TaskStatus::Failed]);
            Task::factory()->count(1)->create(['status' => TaskStatus::Timeout]);
            Task::factory()->count(1)->create(['status' => TaskStatus::Cancelled]);
            Task::factory()->count(2)->create(['status' => TaskStatus::Running]);

            $response = $this->postJson('/api/tasks/clear-completed');

            $response->assertStatus(200)
                ->assertJson([
                    'message' => 'Successfully cleared 7 completed tasks',
                    'deleted_count' => 7,
                ]);

            // Check that only running tasks remain
            expect(Task::where('status', TaskStatus::Running)->count())->toBe(2);
            expect(Task::whereIn('status', [
                TaskStatus::Finished,
                TaskStatus::Failed,
                TaskStatus::Timeout,
                TaskStatus::Cancelled,
            ])->count())->toBe(0);
        });

        it('returns zero when no completed tasks exist', function () {
            Task::factory()->count(3)->create(['status' => TaskStatus::Running]);

            $response = $this->postJson('/api/tasks/clear-completed');

            $response->assertStatus(200)
                ->assertJson([
                    'message' => 'Successfully cleared 0 completed tasks',
                    'deleted_count' => 0,
                ]);
        });
    });
});
