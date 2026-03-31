<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tasks;

use App\Modules\TaskRunner\Exceptions\TaskValidationException;
use App\Modules\TaskRunner\Task;

/**
 * GenerateRsaKeyPair task for generating RSA SSH key pairs.
 */
class GenerateRsaKeyPair extends Task
{
    public string $view = 'task-runner::tasks.generate-rsa-key-pair';

    public ?int $timeout = 600; // 10 minutes timeout for SSH key generation

    public function __construct(
        public string $privatePath,
        public int $bits = 4096
    ) {}

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

        if ($this->bits < 1024 || $this->bits > 8192) {
            $errors['bits'] = 'bits must be between 1024 and 8192.';
        }

        $script = $this->renderScript();
        if (strpos($script, 'ssh-keygen -t rsa') === false) {
            $errors['script'] = 'Script must contain ssh-keygen -t rsa command.';
        }

        if (! empty($errors)) {
            throw TaskValidationException::withErrors($errors);
        }
    }
}
