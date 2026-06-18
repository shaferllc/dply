<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Commands;

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task;
use Illuminate\Console\Command;

class TaskShowCommand extends Command
{
    protected $signature = 'task:show 
                            {task : Task ID or name}
                            {--output : Show task output}
                            {--error : Show task error}
                            {--follow : Follow task output in real-time}
                            {--format=table : Output format (table, json)}';

    protected $description = 'Show detailed information about a task';

    public function handle(): int
    {
        $taskIdentifier = $this->argument('task');
        $showOutput = $this->option('output');
        $showError = $this->option('error');
        $follow = $this->option('follow');
        $format = $this->option('format');

        // Try to find task by ID first, then by name
        $task = Task::find($taskIdentifier);

        if (! $task) {
            $task = Task::where('name', $taskIdentifier)->latest()->first();
        }

        if (! $task) {
            $this->error("Task not found: {$taskIdentifier}");

            return 1;
        }

        if ($follow && $task->status->isActive()) {
            return $this->followTask($task);
        }

        if ($format === 'json') {
            $this->outputJson($task, $showOutput, $showError);
        } else {
            $this->outputTable($task, $showOutput, $showError);
        }

        return 0;
    }

    protected function outputTable(Task $task, bool $showOutput, bool $showError): void
    {
        $this->info('Task Details');
        $this->line('===========');

        $this->table([], [
            ['ID', $task->id],
            ['Name', $task->name],
            ['Status', $this->formatStatus($task->status)],
            ['Created', $task->created_at->format('Y-m-d H:i:s')],
            ['Started', $task->started_at?->format('Y-m-d H:i:s') ?? 'Not started'],
            ['Completed', $task->completed_at?->format('Y-m-d H:i:s') ?? 'Not completed'],
            ['Duration', $task->getDuration() > 0 ? number_format((float) $task->getDuration(), 2).'s' : 'N/A'],
            ['Exit Code', $task->exit_code ?? 'N/A'],
            ['Progress', $task->getProgressAttribute() !== null ? $task->getProgressAttribute().'%' : 'N/A'],
        ]);

        if ($showError && $task->getErrorAttribute()) {
            $this->newLine();
            $this->error('Error Output');
            $this->line('============');
            $this->line($task->getErrorAttribute());
        }

        if ($showOutput && $task->output) {
            $this->newLine();
            $this->info('Task Output');
            $this->line('===========');
            $this->line($task->output);
        }

        if (! $showOutput && ! $showError && ($task->output || $task->getErrorAttribute())) {
            $this->newLine();
            $this->comment('Use --output to show task output');
            $this->comment('Use --error to show task error');
        }
    }

    protected function outputJson(Task $task, bool $showOutput, bool $showError): void
    {
        $data = [
            'id' => $task->id,
            'name' => $task->name,
            'status' => $task->status->value,
            'created_at' => $task->created_at->toISOString(),
            'started_at' => $task->started_at?->toISOString(),
            'completed_at' => $task->completed_at?->toISOString(),
            'duration' => $task->getDuration(),
            'exit_code' => $task->exit_code,
            'progress' => $task->getProgressAttribute(),
        ];

        if ($showError) {
            $data['error'] = $task->getErrorAttribute();
        }

        if ($showOutput) {
            $data['output'] = $task->output;
        }

        $this->output->write(json_encode($data, JSON_PRETTY_PRINT));
    }

    protected function followTask(Task $task): int
    {
        $this->info("Following task: {$task->name} (ID: {$task->id})");
        $this->line('Press Ctrl+C to stop following');
        $this->newLine();

        $lastOutputLength = 0;
        $lastErrorLength = 0;

        while ($task->status->isActive()) {
            $task->refresh();

            // Show new output
            if ($task->output && strlen($task->output) > $lastOutputLength) {
                $newOutput = substr($task->output, $lastOutputLength);
                $this->line($newOutput);
                $lastOutputLength = strlen($task->output);
            }

            // Show new error
            $errorOutput = $task->getErrorAttribute() ?? '';
            if ($errorOutput !== '' && strlen($errorOutput) > $lastErrorLength) {
                $newError = substr($errorOutput, $lastErrorLength);
                $this->error($newError);
                $lastErrorLength = strlen($errorOutput);
            }

            // Show progress
            $progress = $task->getProgressAttribute();
            if ($progress !== null) {
                $this->output->write("\r\x1B[2KProgress: {$progress}%");
            }

            sleep(1);
        }

        $this->newLine();
        $this->info("Task completed with status: {$task->status->value}");

        if ($task->exit_code !== null) {
            $this->info("Exit code: {$task->exit_code}");
        }

        return 0;
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
        };
    }
}
