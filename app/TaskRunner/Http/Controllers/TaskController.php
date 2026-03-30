<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Http\Controllers;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Facades\TaskRunner;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\ParallelTaskExecutor;
use App\Modules\TaskRunner\TaskChain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

class TaskController extends Controller
{
    /**
     * List tasks with filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Task::query();

        // Apply filters
        if ($request->has('name')) {
            $query->where('name', 'like', '%'.$request->input('name').'%');
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->boolean('running')) {
            $query->where('status', TaskStatus::Running);
        }

        if ($request->boolean('failed')) {
            $query->whereIn('status', TaskStatus::getFailedStatuses());
        }

        if ($request->has('recent')) {
            $hours = (int) $request->input('recent', 24);
            $query->where('created_at', '>=', now()->subHours($hours));
        }

        // Apply sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Apply pagination
        $perPage = min((int) $request->input('per_page', 50), 100);
        $tasks = $query->paginate($perPage);

        return response()->json([
            'data' => $tasks->items(),
            'pagination' => [
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
            ],
        ]);
    }

    /**
     * Show a specific task.
     */
    public function show(string $task): JsonResponse
    {
        $taskModel = Task::find($task);

        if (! $taskModel) {
            $taskModel = Task::where('name', $task)->latest()->first();
        }

        if (! $taskModel) {
            return response()->json(['error' => 'Task not found'], 404);
        }

        return response()->json([
            'data' => $taskModel,
        ]);
    }

    /**
     * Run a single task.
     */
    public function run(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'command' => 'required|string',
            'name' => 'nullable|string',
            'timeout' => 'nullable|integer|min:1',
            'connection' => 'nullable|string',
            'view' => 'nullable|string',
            'data' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $command = $request->input('command');
            $name = $request->input('name', $command);
            $timeout = $request->input('timeout');
            $connection = $request->input('connection');
            $view = $request->input('view');
            $data = $request->input('data', []);

            if ($view) {
                $task = AnonymousTask::view($name, $view, $data);
            } else {
                $task = AnonymousTask::command($name, $command);
            }

            if ($timeout) {
                $task->timeout($timeout);
            }

            if ($connection) {
                $task->onConnection($connection);
            }

            $result = TaskRunner::run($task);

            return response()->json([
                'data' => [
                    'name' => $task->getName(),
                    'successful' => $result->isSuccessful(),
                    'exit_code' => $result->getExitCode(),
                    'output' => $result->getBuffer(),
                    'timeout' => $result->isTimeout(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Run multiple tasks in parallel.
     */
    public function runParallel(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tasks' => 'required|array|min:1',
            'tasks.*.command' => 'required|string',
            'tasks.*.name' => 'nullable|string',
            'max_concurrency' => 'nullable|integer|min:1|max:50',
            'timeout' => 'nullable|integer|min:1',
            'stop_on_failure' => 'nullable|boolean',
            'min_success' => 'nullable|integer|min:1',
            'max_failures' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $tasks = $request->input('tasks');
            $maxConcurrency = $request->input('max_concurrency', 5);
            $timeout = $request->input('timeout');
            $stopOnFailure = $request->boolean('stop_on_failure', false);
            $minSuccess = $request->input('min_success');
            $maxFailures = $request->input('max_failures');

            $executor = ParallelTaskExecutor::make()
                ->withMaxConcurrency($maxConcurrency)
                ->stopOnFailure($stopOnFailure);

            if ($timeout) {
                $executor->withTimeout($timeout);
            }

            if ($minSuccess) {
                $executor->withMinSuccess($minSuccess);
            }

            if ($maxFailures) {
                $executor->withMaxFailures($maxFailures);
            }

            foreach ($tasks as $taskData) {
                $name = $taskData['name'] ?? $taskData['command'];
                $task = AnonymousTask::command($name, $taskData['command']);
                $executor->add($task);
            }

            $results = $executor->run();

            return response()->json([
                'data' => $results,
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Run a task chain.
     */
    public function runChain(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tasks' => 'required|array|min:1',
            'tasks.*.command' => 'required|string',
            'tasks.*.name' => 'nullable|string',
            'parallel' => 'nullable|boolean',
            'max_concurrency' => 'nullable|integer|min:1|max:50',
            'timeout' => 'nullable|integer|min:1',
            'stop_on_failure' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $tasks = $request->input('tasks');
            $parallel = $request->boolean('parallel', false);
            $maxConcurrency = $request->input('max_concurrency', 5);
            $timeout = $request->input('timeout');
            $stopOnFailure = $request->boolean('stop_on_failure', true);

            $chain = TaskChain::make()
                ->stopOnFailure($stopOnFailure);

            if ($timeout) {
                $chain->withTimeout($timeout);
            }

            if ($parallel) {
                $chain->withParallel(true, $maxConcurrency);
            }

            foreach ($tasks as $taskData) {
                $name = $taskData['name'] ?? $taskData['command'];
                $chain->addCommand($name, $taskData['command']);
            }

            $results = $chain->run();

            return response()->json([
                'data' => $results,
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get task output in real-time (SSE).
     */
    public function stream(string $task): JsonResponse
    {
        $taskModel = Task::find($task);

        if (! $taskModel) {
            $taskModel = Task::where('name', $task)->latest()->first();
        }

        if (! $taskModel) {
            return response()->json(['error' => 'Task not found'], 404);
        }

        // For now, return the current output
        // In a real implementation, you'd use Server-Sent Events
        return response()->json([
            'data' => [
                'task_id' => $taskModel->id,
                'name' => $taskModel->name,
                'status' => $taskModel->status->value,
                'output' => $taskModel->output,
                'error' => $taskModel->error,
                'progress' => $taskModel->progress,
                'is_running' => $taskModel->status->isActive(),
            ],
        ]);
    }

    /**
     * Cancel a running task.
     */
    public function cancel(string $task): JsonResponse
    {
        $taskModel = Task::find($task);

        if (! $taskModel) {
            $taskModel = Task::where('name', $task)->latest()->first();
        }

        if (! $taskModel) {
            return response()->json(['error' => 'Task not found'], 404);
        }

        if (! $taskModel->status->isActive()) {
            return response()->json(['error' => 'Task is not running'], 400);
        }

        // In a real implementation, you'd cancel the actual process
        $taskModel->update([
            'status' => TaskStatus::Cancelled,
            'completed_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'task_id' => $taskModel->id,
                'status' => 'cancelled',
                'message' => 'Task cancelled successfully',
            ],
        ]);
    }

    /**
     * Delete a task.
     */
    public function destroy(string $task): JsonResponse
    {
        $taskModel = Task::find($task);

        if (! $taskModel) {
            $taskModel = Task::where('name', $task)->latest()->first();
        }

        if (! $taskModel) {
            return response()->json(['error' => 'Task not found'], 404);
        }

        $taskModel->delete();

        return response()->json([
            'data' => [
                'message' => 'Task deleted successfully',
            ],
        ]);
    }

    /**
     * Get task statistics.
     */
    public function stats(): JsonResponse
    {
        $stats = [
            'total' => Task::count(),
            'running' => Task::where('status', TaskStatus::Running)->count(),
            'completed' => Task::where('status', TaskStatus::Finished)->count(),
            'failed' => Task::whereIn('status', TaskStatus::getFailedStatuses())->count(),
            'pending' => Task::where('status', TaskStatus::Pending)->count(),
            'recent_24h' => Task::where('created_at', '>=', now()->subDay())->count(),
            'recent_7d' => Task::where('created_at', '>=', now()->subWeek())->count(),
            'avg_duration' => Task::whereNotNull('duration')->avg('duration'),
            'success_rate' => $this->calculateSuccessRate(),
        ];

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Get tasks by status.
     */
    public function byStatus(string $status): JsonResponse
    {
        $validStatuses = array_map(fn ($case) => $case->value, TaskStatus::cases());

        if (! in_array($status, $validStatuses)) {
            return response()->json(['error' => 'Invalid status'], 400);
        }

        $tasks = Task::where('status', $status)
            ->latest()
            ->paginate(50);

        return response()->json([
            'data' => $tasks->items(),
            'pagination' => [
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
            ],
        ]);
    }

    /**
     * Search tasks.
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->input('q');

        if (! $query) {
            return response()->json(['error' => 'Search query required'], 400);
        }

        $tasks = Task::where('name', 'like', "%{$query}%")
            ->orWhere('output', 'like', "%{$query}%")
            ->orWhere('error', 'like', "%{$query}%")
            ->latest()
            ->paginate(50);

        return response()->json([
            'data' => $tasks->items(),
            'pagination' => [
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
            ],
        ]);
    }

    /**
     * Calculate success rate.
     */
    protected function calculateSuccessRate(): float
    {
        $total = Task::whereIn('status', [TaskStatus::Finished, TaskStatus::Failed])->count();

        if ($total === 0) {
            return 0.0;
        }

        $successful = Task::where('status', TaskStatus::Finished)->count();

        return round(($successful / $total) * 100, 2);
    }

    /**
     * Clear completed tasks.
     */
    public function clearCompleted(): JsonResponse
    {
        try {
            $deletedCount = Task::whereIn('status', [
                TaskStatus::Finished,
                TaskStatus::Failed,
                TaskStatus::Timeout,
                TaskStatus::Cancelled,
            ])->delete();

            return response()->json([
                'message' => "Successfully cleared {$deletedCount} completed tasks",
                'deleted_count' => $deletedCount,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
