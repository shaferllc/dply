<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Concerns;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Contracts\HasCallbacks;
use Illuminate\Support\Collection;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ConvertsTaskModel
{


    /**
     * Get the task as a model instance.
     */
    public function toModel(): TaskModel
    {
        if ($this->taskModel) {
            return $this->taskModel;
        }

        // Create a new model instance
        $model = new TaskModel;
        $model->fill([
            'name' => $this->getName(),
            'action' => $this->getAction(),
            'script' => $this->getScript(),
            'timeout' => $this->timeout,
            'user' => $this->user,
            'status' => $this->status,
            'output' => $this->output,
            'exit_code' => $this->exitCode,
            'options' => $this->options,
            'instance' => TaskModel::storeInstance($this),
        ]);

        return $model;
    }

    /**
     * Create a task from a model instance.
     */
    public static function fromModel(TaskModel $model): static
    {
        $task = new static;
        $task->taskModel = $model;
        $task->status = $model->status;
        $task->output = $model->output ?? '';
        $task->exitCode = $model->exit_code;
        $task->timeout = $model->timeout ?? 300;
        $task->user = $model->user ?? 'root';
        $task->options = $model->options ?? [];

        return $task;
    }

    /**
     * Get the task performance metrics.
     */

    /**
     * Format duration in a human-readable format.
     */
    protected function formatDuration(int $seconds): string
    {
        if ($seconds < 1) {
            return round($seconds * 1000, 2).'ms';
        }

        if ($seconds < 60) {
            return round($seconds, 2).'s';
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        return "{$minutes}m ".round($remainingSeconds, 2).'s';
    }

    /**
     * Get the task summary.
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->taskModel?->id,
            'name' => $this->getName(),
            'action' => $this->getAction(),
            'status' => $this->status->value,
            'exit_code' => $this->exitCode,
            'user' => $this->user,
            'timeout' => $this->timeout,
            'output_size' => strlen($this->output),
            'output_lines' => count($this->outputLines()),
            'successful' => $this->isSuccessful(),
            'finished' => $this->isFinished(),
            'failed' => $this->isFailed(),
            'timed_out' => $this->isTimedOut(),
            'options' => $this->options,
            'performance' => $this->getPerformanceMetrics(),
        ];
    }

    /**
     * Returns all public properties of the task.
     *
     * @return Collection<string, mixed>
     */
    public function getPublicProperties(): Collection
    {
        $properties = (new ReflectionObject($this))->getProperties(ReflectionProperty::IS_PUBLIC);

        return Collection::make($properties)->mapWithKeys(function (ReflectionProperty $property) {
            return [$property->getName() => $property->getValue($this)];
        });
    }

    /**
     * Returns all public methods of the task.
     *
     * @return Collection<int|string, Closure|null>
     */
    public function getPublicMethods(): Collection
    {
        $macros = Collection::make(static::$macros)
            ->mapWithKeys(function ($macro, $name) {
                return [$name => Closure::bind($macro, $this, get_class($this))];
            });

        $methods = (new ReflectionObject($this))->getMethods(ReflectionProperty::IS_PUBLIC);

        $methodCollection = Collection::make($methods)
            ->filter(function (ReflectionMethod $method) {
                if ($method->isConstructor() || $method->isDestructor() || $method->isStatic()) {
                    return false;
                }

                $ignoreMethods = [
                    'getAction',
                    'getData',
                    'getScript',
                    'getName',
                    'getTimeout',
                    'getView',
                    'validate',
                    'getPublicProperties',
                    'getPublicMethods',
                    'getViewData',
                ];

                if (in_array($method->getName(), $ignoreMethods)) {
                    return false;
                }

                return true;
            })
            ->mapWithKeys(function (ReflectionMethod $method) {
                return [$method->getName() => $method->getClosure($this)];
            });

        return $macros->merge($methodCollection);
    }

    /**
     * Returns all data that should be passed to the view.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->getPublicProperties()
            ->merge($this->getPublicMethods())
            ->merge($this->getViewData())
            ->all();
    }

    /**
     * Returns the data that should be passed to the view.
     *
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        return [];
    }

    /**
     * Convert this task to an AnonymousTask for enhanced TaskRunner features.
     */
    public function toAnonymousTask(): AnonymousTask
    {
        return AnonymousTask::script(
            $this->getName(),
            $this->getScript(),
            $this->getOptions()
        );
    }

    /**
     * Check if this task is compatible with enhanced TaskRunner features.
     */
    public function isEnhancedCompatible(): bool
    {
        return method_exists($this, 'render') || method_exists($this, 'getScript');
    }

    /**
     * Get task information for monitoring and management.
     */
    public function getTaskInfo(): array
    {
        return [
            'class' => static::class,
            'name' => $this->getName(),
            'compatible' => $this->isEnhancedCompatible(),
            'has_callbacks' => $this instanceof HasCallbacks,
            'has_options' => ! empty($this->options),
            'has_task_model' => $this->taskModel !== null,
        ];
    }
}
