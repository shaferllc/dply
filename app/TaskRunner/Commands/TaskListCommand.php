<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Commands;

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class TaskListCommand extends Command
{
    protected $signature = 'task:list 
                            {--name= : Filter by task name}
                            {--status= : Filter by status (pending, running, completed, failed)}
                            {--limit=50 : Number of tasks to show}
                            {--running : Show only running tasks}
                            {--recent : Show only recent tasks (last 24 hours)}
                            {--failed : Show only failed tasks}
                            {--format=table : Output format (table, json, csv)}
                            {--detailed : Show detailed information}';

    protected $description = 'List and manage tasks';

    public function handle(): int
    {
        $query = Task::query();

        // Apply filters
        if ($name = $this->option('name')) {
            $query->where('name', 'like', "%{$name}%");
        }

        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }

        if ($this->option('running')) {
            $query->where('status', TaskStatus::Running);
        }

        if ($this->option('failed')) {
            $query->whereIn('status', TaskStatus::getFailedStatuses());
        }

        if ($this->option('recent')) {
            $query->where('created_at', '>=', now()->subDay());
        }

        $limit = (int) $this->option('limit');
        $tasks = $query->latest()->limit($limit)->get();

        if ($tasks->isEmpty()) {
            $this->line('No tasks found.');

            return 0;
        }

        $format = $this->option('format');
        $verbose = $this->option('detailed');

        switch ($format) {
            case 'json':
                $this->outputJson($tasks, $verbose);
                break;
            case 'csv':
                $this->outputCsv($tasks, $verbose);
                break;
            default:
                $this->outputTable($tasks, $verbose);
                break;
        }

        return 0;
    }

    protected function outputTable(Collection $tasks, bool $verbose): void
    {
        $headers = ['ID', 'Name', 'Status', 'Created', 'Duration', 'Exit Code'];

        if ($verbose) {
            $headers = array_merge($headers, ['Started', 'Completed', 'Progress', 'Error']);
        }

        $rows = $tasks->map(function (Task $task) use ($verbose) {
            $row = [
                $task->id,
                $task->name,
                $this->formatStatus($task->status),
                $task->created_at->format('Y-m-d H:i:s'),
                $task->getDuration() ? number_format($task->getDuration(), 2).'s' : '-',
                $task->exit_code ?? '-',
            ];

            if ($verbose) {
                $row = array_merge($row, [
                    $task->started_at?->format('Y-m-d H:i:s') ?? '-',
                    $task->completed_at?->format('Y-m-d H:i:s') ?? '-',
                    $task->progress ? $task->progress.'%' : '-',
                    $task->error ? substr($task->error, 0, 50).'...' : '-',
                ]);
            }

            return $row;
        })->toArray();

        $this->table($headers, $rows);
    }

    protected function outputJson(Collection $tasks, bool $verbose): void
    {
        $data = $tasks->map(function (Task $task) use ($verbose) {
            $taskData = [
                'id' => $task->id,
                'name' => $task->name,
                'status' => $task->status->value,
                'created_at' => $task->created_at->toISOString(),
                'duration' => $task->getDuration(),
                'exit_code' => $task->exit_code,
            ];

            if ($verbose) {
                $taskData = array_merge($taskData, [
                    'started_at' => $task->started_at?->toISOString(),
                    'completed_at' => $task->completed_at?->toISOString(),
                    'progress' => $task->progress,
                    'error' => $task->error,
                    'output' => $task->output,
                ]);
            }

            return $taskData;
        });

        $this->line(json_encode($data, JSON_PRETTY_PRINT));
    }

    protected function outputCsv(Collection $tasks, bool $verbose): void
    {
        $headers = ['ID', 'Name', 'Status', 'Created', 'Duration', 'Exit Code'];

        if ($verbose) {
            $headers = array_merge($headers, ['Started', 'Completed', 'Progress', 'Error']);
        }

        $this->output->writeln(implode(',', $headers));

        foreach ($tasks as $task) {
            $row = [
                $task->id,
                '"'.str_replace('"', '""', $task->name).'"',
                $task->status->value,
                $task->created_at->format('Y-m-d H:i:s'),
                $task->getDuration() ? number_format($task->getDuration(), 2).'s' : '',
                $task->exit_code ?? '',
            ];

            if ($verbose) {
                $row = array_merge($row, [
                    $task->started_at?->format('Y-m-d H:i:s') ?? '',
                    $task->completed_at?->format('Y-m-d H:i:s') ?? '',
                    $task->progress ?? '',
                    '"'.str_replace('"', '""', $task->error ?? '').'"',
                ]);
            }

            $this->output->writeln(implode(',', $row));
        }
    }

    protected function formatStatus(TaskStatus $status): string
    {
        return match ($status) {
            TaskStatus::Pending => '<fg=yellow>Pending</>',
            TaskStatus::Running => '<fg=blue>Running</>',
            TaskStatus::Finished => '<fg=green>Finished</>',
            TaskStatus::Failed => '<fg=red>Failed</>',
            TaskStatus::Timeout => '<fg=red>Timeout</>',
            TaskStatus::Cancelled => '<fg=yellow>Cancelled</>',
            TaskStatus::UploadFailed => '<fg=red>Upload Failed</>',
            TaskStatus::ConnectionFailed => '<fg=red>Connection Failed</>',
            default => $status->value,
        };
    }
}
