<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Concerns;

use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Helper;
use App\Modules\TaskRunner\Models\Task as TaskModel;
use App\Modules\TaskRunner\PendingTask;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesTaskFactory
{


    /**
     * Returns a new PendingTask with this task.
     */
    public function pending(): PendingTask
    {
        return new PendingTask($this);
    }

    /**
     * Returns a new PendingTask with this task.
     */
    public static function make(...$arguments): PendingTask
    {
        return static::createInstance(...$arguments)->pending();
    }

    /**
     * Helper methods to create a new PendingTask.
     *
     * @param  string  $name  The name of the method.
     *
     * @see Task::make()
     * @see Task::pending()
     */

    /**
     * Create an instance of the current class.
     */
    protected static function createInstance(...$arguments): static
    {
        // If we're in fake mode, create a fake instance
        if (static::isFake()) {
            return static::createFakeInstance(...$arguments);
        }

        return new (static::class)(...$arguments);
    }

    /**
     * Enable fake mode for testing.
     */
    public static function fake(): void
    {
        static::$fakeMode = true;
    }

    /**
     * Disable fake mode.
     */
    public static function unfake(): void
    {
        static::$fakeMode = false;
    }

    /**
     * Check if we're in fake mode.
     */
    public static function isFake(): bool
    {
        return static::$fakeMode;
    }

    /**
     * Check if background tracking should be disabled (for fake mode).
     */
    public static function shouldDisableBackgroundTracking(): bool
    {
        return static::isFake();
    }

    /**
     * Create a fake instance for testing that sets up the task model
     * and allows running the actual task code without background monitoring.
     */
    protected static function createFakeInstance(...$arguments): static
    {
        $task = static::createInstance(...$arguments);

        // Create a task model for testing
        $taskModel = TaskModel::create([
            'name' => $task->getName(),
            'action' => $task->getAction(),
            'status' => TaskStatus::Pending,
            'script' => $task->getScript(),
            'options' => json_encode($task->getOptions()),
            'timeout' => $task->getTimeout(),
            'user' => $task->getUser(),
            'created_by' => null,
        ]);

        // Set the task model on the task
        $task->setTaskModel($taskModel);

        return $task;
    }
}
