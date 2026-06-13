<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Concerns;

use App\Modules\TaskRunner\FakeTask;
use App\Modules\TaskRunner\PendingTask;
use App\Modules\TaskRunner\ProcessOutput;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesTaskRunnerFakes
{


    /**
     * @param  array<int, string>|string  $tasksToFake
     */
    public function fake(array|string $tasksToFake = []): self
    {
        $this->tasksToFake = Collection::wrap($tasksToFake)
            ->map(fn ($value, $key) => $this->createFakeTask($value, $key))
            ->filter()
            ->values()
            ->all();

        $this->storePersistentFake();

        return $this;
    }

    /**
     * Disable fake mode and reset faking state.
     */
    public function unfake(): self
    {
        $this->tasksToFake = false;
        $this->tasksToDispatch = [];
        $this->preventStrayTasks = false;
        $this->storePersistentFake();

        return $this;
    }

    protected function createFakeTask(mixed $value, mixed $key): ?FakeTask
    {
        if (is_string($key) && is_string($value)) {
            return new FakeTask($key, ProcessOutput::make($value)->setExitCode(0));
        }

        if (is_string($value)) {
            return new FakeTask($value, ProcessOutput::make()->setExitCode(0));
        }

        if (is_string($key) && $value instanceof ProcessOutput) {
            return new FakeTask($key, $value);
        }

        return null;
    }

    /**
     * Don't fake specific tasks.
     *
     * @param  array<int, string>|string  $taskToDispatch
     */
    public function dontFake(array|string $taskToDispatch): self
    {
        $this->tasksToDispatch = array_merge($this->tasksToDispatch, Arr::wrap($taskToDispatch));

        $this->storePersistentFake();

        return $this;
    }

    /**
     * Prevents stray tasks from being executed.
     */
    public function preventStrayTasks(bool $prevent = true): self
    {
        $this->preventStrayTasks = $prevent;

        return $this;
    }

    /**
     * Returns a boolean if the task should be faked or the corresponding fake task.
     */
    public function taskShouldBeFaked(PendingTask $pendingTask): bool|FakeTask
    {
        foreach ($this->tasksToDispatch as $dontFake) {
            if ($pendingTask->task instanceof $dontFake) {
                return false;
            }
        }

        if ($this->tasksToFake === []) {
            return new FakeTask(get_class($pendingTask->task), ProcessOutput::make()->setExitCode(0));
        }

        if ($this->tasksToFake === false && ! config('task-runner.persistent_fake.enabled')) {
            return false;
        }

        $fakeTask = collect($this->tasksToFake ?: [])->first(function (FakeTask $fakeTask) use ($pendingTask) {
            return $pendingTask->task instanceof $fakeTask->taskClass;
        });

        if (! $fakeTask && $this->preventStrayTasks) {
            throw new RuntimeException('Attempted dispatch task ['.get_class($pendingTask->task).'] without a matching fake.');
        }

        return $fakeTask ?: false;
    }

    /**
     * Returns the dispatched tasks, filtered by a callback.
     *
     * @return Collection<int, FakeTask>
     */
    protected function faked(callable $callback): Collection
    {
        $this->loadPersistentFake();

        return collect($this->dispatchedTasks)
            ->filter(function (PendingTask $pendingTask) use ($callback) {
                $refFunction = new \ReflectionFunction($callback);

                $parameters = $refFunction->getParameters();

                if ($parameters[0]->getType()) {
                    $typeHint = (string) $parameters[0]->getType();
                    if (! $typeHint || $typeHint === PendingTask::class) {
                        return $callback($pendingTask);
                    }
                }

                return $callback($pendingTask->task);
            })
            ->values();
    }
}
