<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Concerns;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\ParallelTaskExecutor;
use App\Modules\TaskRunner\Task;
use App\Modules\TaskRunner\TaskChain;
use Throwable;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait RunsTaskRunnerParallelChains
{
    /**
     * Run multiple tasks in parallel with model support.
     *
     * @param  array<string, mixed>  $tasks
     * @return array<string, mixed>
     */
    public function runParallelWithModels(array $tasks): array
    {
        $results = [];
        $promises = [];

        foreach ($tasks as $index => $task) {
            if ($task instanceof Task) {
                $promises[$index] = function () use ($task) {
                    return $this->runWithModel($task);
                };
            }
        }

        foreach ($promises as $index => $promise) {
            try {
                $results[$index] = $promise();
            } catch (Throwable $e) {
                $results[$index] = $e;
            }
        }

        return $results;
    }

    /**
     * Run tasks in a chain with model support.
     *
     * @param  array<string, mixed>  $tasks
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function runChainWithModels(array $tasks, array $options = []): array
    {
        $chain = $this->chain();

        foreach ($tasks as $task) {
            if ($task instanceof Task) {
                $chain->add($task);
            }
        }

        if (! empty($options)) {
            $chain->withOptions($options);
        }

        return $chain->run();
    }

    /**
     * Run multiple tasks in parallel.
     *
     * @param  array<string, mixed>  $tasks
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function runParallel(array $tasks, array $options = []): array
    {
        $parallelExecutor = ParallelTaskExecutor::make($this);

        foreach ($tasks as $task) {
            $parallelExecutor->add($task);
        }

        $parallelExecutor->withOptions($options);

        return $parallelExecutor->run();
    }

    /**
     * Run a task chain in parallel.
     *
     * @return array<string, mixed>
     */
    public function runChainParallel(TaskChain $chain): array
    {
        return $chain->withParallel(true)->run();
    }

    /**
     * Run multiple task chains in parallel.
     *
     * @param  array<string, mixed>  $chains
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function runChainsParallel(array $chains, array $options = []): array
    {
        $parallelExecutor = ParallelTaskExecutor::make($this);

        foreach ($chains as $chain) {
            $chainTask = AnonymousTask::callback(
                "Chain: {$chain->getChainId()}",
                function () use ($chain) {
                    return $chain->run();
                }
            );
            $parallelExecutor->add($chainTask);
        }

        $parallelExecutor->withOptions($options);

        return $parallelExecutor->run();
    }

    /**
     * Create a new task chain.
     */
    public function chain(): TaskChain
    {
        return new TaskChain($this);
    }

    /**
     * Run a task chain.
     *
     * @return array<string, mixed>
     */
    public function runChain(TaskChain $chain): array
    {
        return $chain->run();
    }

    /**
     * Create and run a task chain with the given tasks.
     *
     * @param  array<string, mixed>  $tasks
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function runTaskChain(array $tasks, array $options = []): array
    {
        $chain = $this->chain();

        foreach ($tasks as $task) {
            $chain->add($task);
        }

        if (! empty($options)) {
            $chain->withOptions($options);
        }

        return $chain->run();
    }
}
