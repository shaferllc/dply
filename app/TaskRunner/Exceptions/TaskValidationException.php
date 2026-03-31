<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Exceptions;

use Exception;

class TaskValidationException extends Exception
{
    /**
     * The validation errors.
     */
    protected array $errors;

    /**
     * Create a new TaskValidationException instance.
     */
    public function __construct(
        string $message = 'Task validation failed.',
        array $errors = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    /**
     * Get the validation errors.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Create a new instance with validation errors.
     */
    public static function withErrors(array $errors, string $message = 'Task validation failed.'): self
    {
        return new self($message, $errors);
    }
}
