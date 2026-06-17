<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Livewire;

use App\Modules\TaskRunner\Models\Task;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Livewire component for monitoring task progress and output.
 */
class TaskMonitor extends Component
{
    public ?string $taskName = null;

    public ?string $taskId = null;

    public bool $showAllTasks = false;

    public bool $autoRefresh = true;

    public int $refreshInterval = 2000; // 2 seconds

    /** @var list<array<string, mixed>> */
    public array $tasks = [];

    /** @var array<string, mixed> */
    public array $taskResults = [];

    /** @var array<string, mixed> */
    public array $taskProgress = [];

    /** @var array<string, mixed> */
    public array $taskOutputs = [];

    /** @var array<string, mixed> */
    public array $taskErrors = [];

    /** @return array<string, mixed> */
    public function getListeners(): array
    {
        return [
            'echo-private:task-runner,TaskStarted' => 'handleTaskStarted',
            'echo-private:task-runner,TaskCompleted' => 'handleTaskCompleted',
            'echo-private:task-runner,TaskFailed' => 'handleTaskFailed',
            'echo-private:task-runner,TaskProgress' => 'handleTaskProgress',
            'echo-private:task-runner,TaskOutput' => 'handleTaskOutput',
            'echo-private:task-runner,TaskChainStarted' => 'handleTaskChainStarted',
            'echo-private:task-runner,TaskChainCompleted' => 'handleTaskChainCompleted',
            'echo-private:task-runner,TaskChainFailed' => 'handleTaskChainFailed',
            'echo-private:task-runner,TaskChainProgress' => 'handleTaskChainProgress',
            'echo-private:task-runner,ParallelTaskStarted' => 'handleParallelTaskStarted',
            'echo-private:task-runner,ParallelTaskCompleted' => 'handleParallelTaskCompleted',
            'echo-private:task-runner,ParallelTaskFailed' => 'handleParallelTaskFailed',
        ];
    }

    public function mount(?string $taskName = null, ?string $taskId = null, bool $showAllTasks = false): void
    {
        $this->taskName = $taskName;
        $this->taskId = $taskId;
        $this->showAllTasks = $showAllTasks;

        $this->loadTasks();
    }

    public function loadTasks(): void
    {
        if ($this->showAllTasks) {
            $this->tasks = Task::latest()
                ->take(50)
                ->get()
                ->map(fn ($task) => $this->formatTaskForDisplay($task))
                ->values()
                ->all();
        } elseif ($this->taskName) {
            $this->tasks = Task::where('name', $this->taskName)
                ->latest()
                ->take(10)
                ->get()
                ->map(fn ($task) => $this->formatTaskForDisplay($task))
                ->values()
                ->all();
        } elseif ($this->taskId) {
            $task = Task::find($this->taskId);
            if ($task) {
                $this->tasks = [$this->formatTaskForDisplay($task)];
            }
        }

        foreach ($this->tasks as $task) {
            if (isset($task['id']) && is_string($task['id'])) {
                $this->loadTaskData($task['id']);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatTaskForDisplay(Task $task): array
    {
        return [
            'id' => $task->id,
            'name' => $task->name,
            'status' => $task->status->value,
            'created_at' => $task->created_at->toISOString(),
            'started_at' => $task->started_at?->toISOString(),
            'completed_at' => $task->completed_at?->toISOString(),
            'duration' => $task->getDuration(),
            'exit_code' => $task->exit_code,
            'output' => $task->output,
            'error' => $task->getErrorAttribute(),
            'progress' => $task->getProgressAttribute(),
            'is_running' => $task->status->value === 'running',
            'is_completed' => in_array($task->status->value, ['completed', 'failed'], true),
        ];
    }

    protected function loadTaskData(string $taskId): void
    {
        $task = Task::find($taskId);
        if (! $task) {
            return;
        }

        $this->taskResults[$taskId] = [
            'status' => $task->status->value,
            'exit_code' => $task->exit_code,
            'duration' => $task->getDuration(),
            'completed_at' => $task->completed_at?->toISOString(),
        ];

        $this->taskOutputs[$taskId] = $task->output ?? '';
        $this->taskErrors[$taskId] = $task->getErrorAttribute() ?? '';
        $this->taskProgress[$taskId] = $task->getProgressAttribute() ?? 0;
    }

    public function refreshTasks(): void
    {
        $this->loadTasks();
    }

    public function toggleAutoRefresh(): void
    {
        $this->autoRefresh = ! $this->autoRefresh;
    }

    public function clearOutput(string $taskId): void
    {
        unset($this->taskOutputs[$taskId]);
        unset($this->taskErrors[$taskId]);
        unset($this->taskProgress[$taskId]);
    }

    public function clearAllOutputs(): void
    {
        $this->taskOutputs = [];
        $this->taskErrors = [];
        $this->taskProgress = [];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function handleTaskStarted(array $event): void
    {
        $taskId = $event['task_id'] ?? null;
        if ($taskId && $this->shouldTrackTask($taskId)) {
            $this->taskResults[$taskId] = [
                'status' => 'running',
                'started_at' => now()->toISOString(),
            ];
            $this->taskProgress[$taskId] = 0;
            $this->taskOutputs[$taskId] = '';
            $this->taskErrors[$taskId] = '';
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function handleTaskCompleted(array $event): void
    {
        $taskId = $event['task_id'] ?? null;
        if ($taskId && $this->shouldTrackTask($taskId)) {
            $this->taskResults[$taskId] = [
                'status' => 'completed',
                'exit_code' => $event['exit_code'] ?? 0,
                'duration' => $event['duration'] ?? 0,
                'completed_at' => now()->toISOString(),
            ];
            $this->taskProgress[$taskId] = 100;
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function handleTaskFailed(array $event): void
    {
        $taskId = $event['task_id'] ?? null;
        if ($taskId && $this->shouldTrackTask($taskId)) {
            $this->taskResults[$taskId] = [
                'status' => 'failed',
                'exit_code' => $event['exit_code'] ?? 1,
                'duration' => $event['duration'] ?? 0,
                'completed_at' => now()->toISOString(),
            ];
            $this->taskErrors[$taskId] = $event['error'] ?? 'Task failed';
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function handleTaskProgress(array $event): void
    {
        $taskId = $event['task_id'] ?? null;
        if ($taskId && $this->shouldTrackTask($taskId)) {
            $this->taskProgress[$taskId] = $event['progress'] ?? 0;
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function handleTaskOutput(array $event): void
    {
        $taskId = $event['task_id'] ?? null;
        if ($taskId && $this->shouldTrackTask($taskId)) {
            $output = $event['output'] ?? '';
            $this->taskOutputs[$taskId] = ($this->taskOutputs[$taskId] ?? '').$output;
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function handleTaskChainStarted(array $event): void
    {
        $chainId = $event['chain_id'] ?? null;
        if ($chainId) {
            // Handle chain events if needed
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function handleTaskChainCompleted(array $event): void
    {
        $chainId = $event['chain_id'] ?? null;
        if ($chainId) {
            // Handle chain completion
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function handleTaskChainFailed(array $event): void
    {
        $chainId = $event['chain_id'] ?? null;
        if ($chainId) {
            // Handle chain failure
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function handleTaskChainProgress(array $event): void
    {
        $chainId = $event['chain_id'] ?? null;
        if ($chainId) {
            // Handle chain progress
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function handleParallelTaskStarted(array $event): void
    {
        $executionId = $event['execution_id'] ?? null;
        if ($executionId) {
            // Handle parallel execution start
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function handleParallelTaskCompleted(array $event): void
    {
        $executionId = $event['execution_id'] ?? null;
        if ($executionId) {
            // Handle parallel execution completion
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function handleParallelTaskFailed(array $event): void
    {
        $executionId = $event['execution_id'] ?? null;
        if ($executionId) {
            // Handle parallel execution failure
        }
    }

    protected function shouldTrackTask(string $taskId): bool
    {
        if ($this->showAllTasks) {
            return true;
        }

        if ($this->taskId && $this->taskId === $taskId) {
            return true;
        }

        if ($this->taskName) {
            $task = Task::find($taskId);

            return $task && $task->name === $this->taskName;
        }

        return false;
    }

    public function render(): View
    {
        return view('task-runner::livewire.task-monitor');
    }
}
