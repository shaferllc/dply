<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Contracts;

use App\Modules\TaskRunner\ProcessOutput;

interface TaskDispatcherInterface
{
    /**
     * Dispatch a task for execution.
     *
     * @param  string  $command  The command to execute.
     * @param  array  $arguments  Optional arguments for the command.
     */
    public function dispatch(string $command, array $arguments = []): ProcessOutput;
}
