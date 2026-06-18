<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Exceptions;

use Exception;
use Throwable;

class TaskExecutionException extends Exception
{
    /**
     * The output from the failed task, if any.
     */
    protected ?string $output;

    /**
     * Create a new TaskExecutionException instance.
     */
    public function __construct(
        string $message = 'Task execution failed.',
        int $code = 0,
        ?string $output = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);

        $this->output = $output;
    }

    /**
     * Get the output from the failed task, if any.
     */
    public function getOutput(): ?string
    {
        return $this->output;
    }
}
