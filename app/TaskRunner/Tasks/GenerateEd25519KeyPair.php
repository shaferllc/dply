<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tasks;

use App\Modules\TaskRunner\Exceptions\TaskValidationException;
use App\Modules\TaskRunner\Task;

/**
 * GenerateEd25519KeyPair task migrated from the Tasks module.
 * Generates Ed25519 SSH key pairs for secure connections.
 */
class GenerateEd25519KeyPair extends Task
{
    public string $view = 'task-runner::tasks.generate-ed25519-key-pair';

    public ?int $timeout = 600; // 10 minutes timeout for SSH key generation

    /**
     * Initializes a new instance of the class.
     */
    public function __construct(public string $privatePath) {}

    /**
     * Get the comment for the key pair.
     */
    public function comment(): string
    {
        return $this->getOption('email') ?? 'dply@dply.io';
    }

    public function validate(): void
    {
        $errors = [];

        if (empty($this->privatePath) || ! is_string($this->privatePath)) {
            $errors['privatePath'] = 'privatePath must be a non-empty string.';
        }

        $script = $this->renderScript();
        if (strpos($script, 'ssh-keygen -t ed25519') === false) {
            $errors['script'] = 'Script must contain ssh-keygen -t ed25519 command.';
        }

        if (! empty($errors)) {
            throw TaskValidationException::withErrors($errors);
        }
    }
}
