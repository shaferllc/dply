<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Concerns;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Models\Task as TaskModel;
use App\Modules\TaskRunner\ProcessOutput;
use Illuminate\Support\Facades\Log;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait CreatesTaskRunnerBuilders
{


    /**
     * Create a task from a model.
     */
    public function createFromModel(TaskModel $taskModel): ?Task
    {
        if (! $taskModel->instance) {
            return null;
        }

        try {
            $instance = unserialize($taskModel->instance);

            if ($instance instanceof Task) {
                return $instance::fromModel($taskModel);
            }
        } catch (Throwable $e) {
            Log::error('Failed to create task from model', [
                'task_model_id' => $taskModel->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Run an anonymous task.
     */
    public function runAnonymous(AnonymousTask $task): ?ProcessOutput
    {
        return $this->run($task->pending());
    }

    /**
     * Create an anonymous task with a script.
     */
    public function anonymous(string $name, string $script, array $options = []): AnonymousTask
    {
        return AnonymousTask::script($name, $script, $options);
    }

    /**
     * Create an anonymous task for a simple command.
     */
    public function command(string $name, string $command, array $options = []): AnonymousTask
    {
        return AnonymousTask::command($name, $command, $options);
    }

    /**
     * Create an anonymous task for multiple commands.
     */
    public function commands(string $name, array $commands, array $options = []): AnonymousTask
    {
        return AnonymousTask::commands($name, $commands, $options);
    }

    /**
     * Create an anonymous task with a view.
     */
    public function view(string $name, string $view, array $data = [], array $options = []): AnonymousTask
    {
        return AnonymousTask::view($name, $view, $data, $options);
    }

    /**
     * Create an anonymous task with a render callback.
     */
    public function callback(string $name, \Closure $callback, array $options = []): AnonymousTask
    {
        return AnonymousTask::callback($name, $callback, $options);
    }
}
