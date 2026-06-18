<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Examples;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\EnhancedTask;
use App\Modules\TaskRunner\Enums\TaskStatus;
use App\Modules\TaskRunner\Facades\TaskRunner;
use App\Modules\TaskRunner\Models\Task;
use App\Modules\TaskRunner\TaskChain;
use Dply\Tasks\BaseTask;

/**
 * Example demonstrating the merged Tasks/TaskRunner system.
 *
 * This example shows how to use both the old Tasks module classes
 * and the new TaskRunner features together.
 */
class MergedTasksExample
{
    /**
     * Example using the old Tasks module BaseTask (now enhanced).
     */
    public function legacyTaskExample(): void
    {
        // Create a task using the old BaseTask (now enhanced)
        $task = new class extends BaseTask
        {
            public function render(): string
            {
                return 'echo "Hello from legacy task!"';
            }
        };

        // Set options (old Tasks module style)
        $task->options(['key' => 'value']);

        // Dispatch using TaskRunner (enhanced functionality)
        $result = TaskRunner::run($task);

        echo 'Legacy task result: '.$result->getBuffer()."\n";
    }

    /**
     * Example using the new EnhancedTask directly.
     */
    public function enhancedTaskExample(): void
    {
        // Create a task using the new EnhancedTask
        $task = new class extends EnhancedTask
        {
            public function render(): string
            {
                return 'echo "Hello from enhanced task!"';
            }

            public function onOutputUpdated(string $output): void
            {
                // Enhanced output handling
                parent::onOutputUpdated($output);
                echo 'Output updated: '.substr($output, 0, 50)."...\n";
            }
        };

        // Set enhanced options
        $task->setOption('timeout', 60);
        $task->setOption('user', 'www-data');

        // Dispatch with enhanced features
        $result = TaskRunner::run($task);

        echo 'Enhanced task result: '.$result->getBuffer()."\n";
    }

    /**
     * Example using task chaining with mixed task types.
     */
    public function taskChainingExample(): void
    {
        // Create a chain with both legacy and enhanced tasks
        $chain = TaskChain::make()
            ->add($this->createLegacyTask())
            ->add($this->createEnhancedTask())
            ->add($this->createAnonymousTask());

        // Run the chain
        $results = TaskRunner::runChain($chain);

        echo 'Chain execution completed with '.count($results)." results\n";
        foreach ($results as $i => $result) {
            echo "Task {$i}: ".($result->isSuccessful() ? 'SUCCESS' : 'FAILED')."\n";
        }
    }

    /**
     * Example showing backward compatibility.
     */
    public function backwardCompatibilityExample(): void
    {
        // This code would work exactly the same as before
        $task = new class extends BaseTask
        {
            public function render(): string
            {
                return 'echo "Backward compatible task"';
            }
        };

        // Old Tasks module methods still work
        $task->options(['legacy' => 'option']);
        $task->setTaskModel(new Task);

        // But now you can also use enhanced features
        $task->setStatus(TaskStatus::Pending);
        $task->setTimeout(120);

        echo 'Task status: '.$task->getStatus()->value."\n";
        echo 'Task timeout: '.$task->getTimeout()." seconds\n";
    }

    /**
     * Example showing migration from old to new patterns.
     */
    public function migrationExample(): void
    {
        // OLD PATTERN (still works)
        $oldTask = new class extends BaseTask
        {
            public function render(): string
            {
                return 'echo "Old pattern task"';
            }
        };
        $oldTask->options(['old' => 'pattern']);

        // NEW PATTERN (enhanced)
        $newTask = new class extends EnhancedTask
        {
            public function render(): string
            {
                return 'echo "New pattern task"';
            }
        };
        $newTask->setOption('new', 'pattern');
        $newTask->setProgress(50);

        // Both can be used together
        $chain = TaskChain::make()
            ->add($oldTask)
            ->add($newTask);

        $results = TaskRunner::runChain($chain);
        echo "Migration example completed\n";
    }

    /**
     * Create a legacy-style task.
     */
    private function createLegacyTask(): BaseTask
    {
        return new class extends BaseTask
        {
            public function render(): string
            {
                return 'echo "Legacy task in chain"';
            }
        };
    }

    /**
     * Create an enhanced task.
     */
    private function createEnhancedTask(): EnhancedTask
    {
        return new class extends EnhancedTask
        {
            public function render(): string
            {
                return 'echo "Enhanced task in chain"';
            }
        };
    }

    /**
     * Create an anonymous task.
     */
    private function createAnonymousTask(): AnonymousTask
    {
        return AnonymousTask::command(
            'Anonymous Task',
            'echo "Anonymous task in chain"'
        );
    }

    /**
     * Run all examples.
     */
    public function runAllExamples(): void
    {
        echo "=== Tasks Module Merge Examples ===\n\n";

        echo "1. Legacy Task Example:\n";
        $this->legacyTaskExample();
        echo "\n";

        echo "2. Enhanced Task Example:\n";
        $this->enhancedTaskExample();
        echo "\n";

        echo "3. Task Chaining Example:\n";
        $this->taskChainingExample();
        echo "\n";

        echo "4. Backward Compatibility Example:\n";
        $this->backwardCompatibilityExample();
        echo "\n";

        echo "5. Migration Example:\n";
        $this->migrationExample();
        echo "\n";

        echo "=== All Examples Completed ===\n";
    }
}
