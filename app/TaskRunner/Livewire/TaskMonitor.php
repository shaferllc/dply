<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Livewire;

use App\Modules\TaskRunner\Models\Task;
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

    public array $tasks = [];

    public array $taskResults = [];

    public array $taskProgress = [];

    public array $taskOutputs = [];

    public array $taskErrors = [];

    /**
     * Get the Echo channel listeners for task events.
     *
     * @return array<string, string>
     */
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

    public function mount(?string $taskName = null, ?string $taskId = null, bool $showAllTasks = false)
    {
        $this->taskName = $taskName;
        $this->taskId = $taskId;
        $this->showAllTasks = $showAllTasks;

        $this->loadTasks();
    }

    public function loadTasks()
    {
        if ($this->showAllTasks) {
            $this->tasks = Task::latest()
                ->take(50)
                ->get()
                ->map(fn ($task) => $this->formatTaskForDisplay($task))
                ->toArray();
        } elseif ($this->taskName) {
            $this->tasks = Task::where('name', $this->taskName)
                ->latest()
                ->take(10)
                ->get()
                ->map(fn ($task) => $this->formatTaskForDisplay($task))
                ->toArray();
        } elseif ($this->taskId) {
            $task = Task::find($this->taskId);
            if ($task) {
                $this->tasks = [$this->formatTaskForDisplay($task)];
            }
        }

        // Load existing results and outputs
        foreach ($this->tasks as $task) {
            $this->loadTaskData($task['id']);
        }
    }

    protected function formatTaskForDisplay(Task $task): array
    {
        return [
            'id' => $task->id,
            'name' => $task->name,
            'status' => $task->status->value,
            'created_at' => $task->created_at->toISOString(),
            'started_at' => $task->started_at?->toISOString(),
            'completed_at' => $task->completed_at?->toISOString(),
            'duration' => $task->duration,
            'exit_code' => $task->exit_code,
            'output' => $task->output,
            'error' => $task->error,
            'progress' => $task->progress,
            'is_running' => $task->status->value === 'running',
            'is_completed' => in_array($task->status->value, ['completed', 'failed']),
        ];
    }

    protected function loadTaskData(string $taskId)
    {
        $task = Task::find($taskId);
        if (! $task) {
            return;
        }

        $this->taskResults[$taskId] = [
            'status' => $task->status->value,
            'exit_code' => $task->exit_code,
            'duration' => $task->duration,
            'completed_at' => $task->completed_at?->toISOString(),
        ];

        $this->taskOutputs[$taskId] = $task->output ?? '';
        $this->taskErrors[$taskId] = $task->error ?? '';
        $this->taskProgress[$taskId] = $task->progress ?? 0;
    }

    public function refreshTasks()
    {
        $this->loadTasks();
    }

    public function toggleAutoRefresh()
    {
        $this->autoRefresh = ! $this->autoRefresh;
    }

    public function clearOutput(string $taskId)
    {
        unset($this->taskOutputs[$taskId]);
        unset($this->taskErrors[$taskId]);
        unset($this->taskProgress[$taskId]);
    }

    public function clearAllOutputs()
    {
        $this->taskOutputs = [];
        $this->taskErrors = [];
        $this->taskProgress = [];
    }

    // Event handlers for real-time updates
    public function handleTaskStarted($event)
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

    public function handleTaskCompleted($event)
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

    public function handleTaskFailed($event)
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

    public function handleTaskProgress($event)
    {
        $taskId = $event['task_id'] ?? null;
        if ($taskId && $this->shouldTrackTask($taskId)) {
            $this->taskProgress[$taskId] = $event['progress'] ?? 0;
        }
    }

    public function handleTaskOutput($event)
    {
        $taskId = $event['task_id'] ?? null;
        if ($taskId && $this->shouldTrackTask($taskId)) {
            $output = $event['output'] ?? '';
            $this->taskOutputs[$taskId] = ($this->taskOutputs[$taskId] ?? '').$output;
        }
    }

    public function handleTaskChainStarted($event)
    {
        $chainId = $event['chain_id'] ?? null;
        if ($chainId) {
            // Handle chain events if needed
        }
    }

    public function handleTaskChainCompleted($event)
    {
        $chainId = $event['chain_id'] ?? null;
        if ($chainId) {
            // Handle chain completion
        }
    }

    public function handleTaskChainFailed($event)
    {
        $chainId = $event['chain_id'] ?? null;
        if ($chainId) {
            // Handle chain failure
        }
    }

    public function handleTaskChainProgress($event)
    {
        $chainId = $event['chain_id'] ?? null;
        if ($chainId) {
            // Handle chain progress
        }
    }

    public function handleParallelTaskStarted($event)
    {
        $executionId = $event['execution_id'] ?? null;
        if ($executionId) {
            // Handle parallel execution start
        }
    }

    public function handleParallelTaskCompleted($event)
    {
        $executionId = $event['execution_id'] ?? null;
        if ($executionId) {
            // Handle parallel execution completion
        }
    }

    public function handleParallelTaskFailed($event)
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

    public function render()
    {
        return view('task-runner::livewire.task-monitor');
    }
}
