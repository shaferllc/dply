<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Concerns;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Contracts\HasCallbacks;
use App\Modules\TaskRunner\Jobs\UpdateTaskOutput;
use App\Modules\TaskRunner\Models\Task as TaskModel;
use App\Modules\TaskRunner\TaskDispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsTaskUrlsAndOutput
{


    /**
     * Called when task output is updated.
     */
    public function onOutputUpdated(string $output): void
    {
        $this->output = $output;

        if ($this->taskModel) {
            $this->taskModel->update(['output' => $output]);
        }
    }

    /**
     * Get the callback URL for the task.
     */
    public function callbackUrl(): ?string
    {
        if (! $this->taskModel || ! $this->taskModel->id) {
            return null;
        }

        return $this instanceof HasCallbacks ? $this->taskModel->callbackUrl() : null;
    }

    /**
     * Get the timeout URL for the task.
     */
    public function timeoutUrl(): ?string
    {
        return $this->taskModel?->timeoutUrl();
    }

    /**
     * Get the failed URL for the task.
     */
    public function failedUrl(): ?string
    {
        return $this->taskModel?->failedUrl();
    }

    /**
     * Get the finished URL for the task.
     */
    public function finishedUrl(): ?string
    {
        return $this->taskModel?->finishedUrl();
    }

    /**
     * Generate a step name from the class name.
     */
    public function stepName(): string
    {
        return Str::snake(class_basename($this));
    }

    /**
     * Get the task output as lines.
     *
     * @return list<string>
     */
    public function outputLines(): array
    {
        if (empty($this->output)) {
            return [];
        }

        return explode(PHP_EOL, $this->output);
    }

    public function tailOutput(int $lines = 10): string
    {
        $outputLines = $this->outputLines();
        $tailLines = array_slice($outputLines, -$lines);

        return implode(PHP_EOL, $tailLines);
    }

    /**
     * Get filtered output (without debug lines).
     */
    public function getFilteredOutput(): string
    {
        if (empty($this->output)) {
            return '';
        }

        $lines = [];
        $currentLines = preg_split('/\r\n|\r|\n/', $this->output);

        if ($currentLines === false) {
            return '';
        }

        foreach ($currentLines as $line) {
            if (! Str::startsWith($line, '+')) {
                $lines[] = $line;
            }
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Get the task output log path on the server.
     */
    public function outputLogPath(): string
    {
        $persistedPath = trim((string) ($this->taskModel?->options['remote_output_path'] ?? ''));
        if ($persistedPath !== '') {
            return $persistedPath;
        }

        if (! $this->taskModel || ! $this->taskModel->server) {
            return '';
        }

        $directory = $this->user === 'root'
            ? $this->taskModel->server->connectionAsRoot()->scriptPath
            : $this->taskModel->server->connectionAsUser()->scriptPath;

        return "{$directory}/task-{$this->taskModel->id}.log";
    }

    /**
     * Update the task output from the server.
     */
    public function updateOutput(bool $handleCallbacks = true): self
    {
        if (! $this->taskModel || ! $this->taskModel->server) {
            return $this;
        }

        try {
            // Use the new TaskRunner to get the output
            $getFileTask = AnonymousTask::command('Get Task Output', "cat {$this->outputLogPath()}");
            $pendingTask = $getFileTask->pending()->onConnection($this->taskModel->server->connectionAsRoot());

            $output = app(TaskDispatcher::class)->run($pendingTask);

            if ($output && $output->isSuccessful()) {
                $this->output = $output->getBuffer();
                $this->taskModel->update(['output' => $this->output]);

                if ($handleCallbacks && $this instanceof HasCallbacks) {
                    $this->onOutputUpdated($this->output);
                }
            }
        } catch (\Exception $e) {
            // Log the error but don't throw
            Log::error('Failed to update task output', [
                'task_id' => $this->taskModel->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return $this;
    }

    /**
     * Update output without handling callbacks.
     */
    public function updateOutputWithoutCallbacks(): self
    {
        return $this->updateOutput(handleCallbacks: false);
    }

    /**
     * Update output in the background.
     */
    public function updateOutputInBackground(): self
    {
        if ($this->taskModel) {
            // Dispatch a job to update the output
            UpdateTaskOutput::dispatch($this->taskModel)
                ->onQueue('task-output');
        }

        return $this;
    }
}
