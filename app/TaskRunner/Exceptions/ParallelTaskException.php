<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Exceptions;

use Exception;

/**
 * Exception thrown when parallel task execution fails.
 */
class ParallelTaskException extends Exception
{
    /**
     * Create a new exception instance.
     */
    public function __construct(string $message = '', int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
