<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Examples;

use App\Modules\TaskRunner\Task;

/**
 * Example of a simple task class.
 * Task classes should only define what the task does, not handle execution logic.
 */
class SimpleTask extends Task
{
    public string $name = 'simple-task';

    public string $action = 'simple';

    /**
     * Define what the task does by returning the script to execute.
     */
    public function render(): string
    {
        return 'echo "This is a simple task"';
    }
}
