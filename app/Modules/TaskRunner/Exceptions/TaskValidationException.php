<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Exceptions;

use Exception;

class TaskValidationException extends Exception
{
    /**
     * The validation errors.
     */
    /** @var array<string, mixed> */
    protected array $errors;

    /**
     * @param array<string, mixed> $errors
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

    /** @return array<string, mixed> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @param array<string, mixed> $errors
     */
    public static function withErrors(array $errors, string $message = 'Task validation failed.'): self
    {
        return new self($message, $errors);
    }
}
