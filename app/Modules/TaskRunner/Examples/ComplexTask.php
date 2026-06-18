<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Examples;

use App\Modules\TaskRunner\Task;

/**
 * Example of a more complex task class with options.
 * Task classes should only define what the task does, not handle execution logic.
 */
class ComplexTask extends Task
{
    public string $name = 'complex-task';

    public string $action = 'complex';

    /**
     * Define what the task does by returning the script to execute.
     */
    public function render(): string
    {
        $message = $this->getOption('message', 'Default message');
        $count = $this->getOption('count', 1);

        $script = '';
        for ($i = 0; $i < $count; $i++) {
            $script .= "echo \"{$message} - iteration ".($i + 1)."\"\n";
        }

        return $script;
    }

    /**
     * Set default options for this task.
     */
    public function __construct()
    {
        // Set default options
        $this->options([
            'message' => 'Hello from complex task',
            'count' => 3,
        ]);
    }
}
