<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Concerns;

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Models\Task as TaskModel;
use Illuminate\Support\Str;
use ReflectionObject;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesTaskState
{


    /**
     * Returns the name of the task.
     */
    public function getName(): string
    {
        $reflection = new ReflectionObject($this);

        if ($reflection->hasProperty('name')) {
            $property = $reflection->getProperty('name');
            $property->setAccessible(true);
            $value = $property->getValue($this);
            if ($value !== null) {
                return $value;
            }
        }

        return Str::headline(class_basename($this));
    }

    /**
     * Returns the action of the task.
     */
    public function getAction(): string
    {
        $reflection = new ReflectionObject($this);

        if ($reflection->hasProperty('action')) {
            $property = $reflection->getProperty('action');
            $property->setAccessible(true);
            $value = $property->getValue($this);
            if ($value !== null) {
                return $value;
            }
        }

        return Str::snake(class_basename($this));
    }

    /**
     * Returns the timeout of the task in seconds.
     */
    public function getTimeout(): ?int
    {
        $timeout = null;
        $reflection = new ReflectionObject($this);

        if ($reflection->hasProperty('timeout')) {
            $property = $reflection->getProperty('timeout');
            $property->setAccessible(true);
            $timeout = $property->getValue($this);
        }

        if ($timeout === null) {
            $timeout = config('task-runner.default_timeout', 60);
        }

        return $timeout > 0 ? $timeout : null;
    }

    /**
     * Returns the view name of the task.
     */
    public function getView(): string
    {
        $view = null;
        $reflection = new ReflectionObject($this);

        if ($reflection->hasProperty('view')) {
            $property = $reflection->getProperty('view');
            $property->setAccessible(true);
            $view = $property->getValue($this);
        }

        if ($view === null) {
            $view = Str::kebab(class_basename($this));
        }

        if (config('task-runner.task_views') !== null) {
            $prefix = rtrim(config('task-runner.task_views'), '');

            return $prefix ? $prefix.'::'.$view : $view;
        }

        return $view;
    }

    /**
     * Set the task model instance.
     */
    public function setTaskModel(TaskModel $taskModel): self
    {
        $this->taskModel = $taskModel;

        return $this;
    }

    /**
     * Get the task model instance.
     */
    public function getTaskModel(): ?TaskModel
    {
        return $this->taskModel;
    }

    /**
     * Set the task options.
      * @param array<string, mixed> $options
     */
    public function options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Get the task options.
     */
    /** @return array<string, mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function setOption(string $key, mixed $value): self
    {
        $this->options[$key] = $value;

        return $this;
    }

    public function getOption(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Set the task status.
     */
    public function setStatus(TaskStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Get the task status.
     */
    public function getStatus(): TaskStatus
    {
        return $this->status;
    }

    /**
     * Set the task output.
     */
    public function setOutput(string $output): self
    {
        $this->output = $output;

        return $this;
    }

    /**
     * Get the task output.
     */
    public function getOutput(): string
    {
        return $this->output;
    }

    /**
     * Set the task exit code.
     */
    public function setExitCode(?int $exitCode): self
    {
        $this->exitCode = $exitCode;

        return $this;
    }

    /**
     * Get the task exit code.
     */
    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * Set the task timeout.
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Set the task user.
     */
    public function setUser(string $user): self
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Get the task user.
     */
    public function getUser(): string
    {
        return $this->user;
    }

    /**
     * Set the task instance data.
     */
    public function setInstance(?string $instance): self
    {
        $this->instance = $instance;

        return $this;
    }

    /**
     * Get the task instance data.
     */
    public function getInstance(): ?string
    {
        return $this->instance;
    }

    /**
     * Check if the task is finished.
     */
    public function isFinished(): bool
    {
        return $this->status === TaskStatus::Finished;
    }

    /**
     * Check if the task is pending.
     */
    public function isPending(): bool
    {
        return $this->status === TaskStatus::Pending;
    }

    /**
     * Check if the task has failed.
     */
    public function isFailed(): bool
    {
        return $this->status === TaskStatus::Failed;
    }

    /**
     * Check if the task has timed out.
     */
    public function isTimedOut(): bool
    {
        return $this->status === TaskStatus::Timeout;
    }

    /**
     * Check if the task was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->isFinished() && $this->exitCode === 0;
    }

    /**
     * Check if the task is older than its timeout.
     */
    public function isOlderThanTimeout(): bool
    {
        if (! $this->taskModel || ! $this->taskModel->created_at) {
            return false;
        }

        return $this->taskModel->created_at->copy()->addSeconds($this->timeout)->isPast();
    }
}
