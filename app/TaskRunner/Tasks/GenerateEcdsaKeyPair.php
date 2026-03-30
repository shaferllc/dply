<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tasks;

use App\Modules\TaskRunner\Exceptions\TaskValidationException;
use App\Modules\TaskRunner\Task;

/**
 * GenerateEcdsaKeyPair task for generating ECDSA SSH key pairs.
 */
class GenerateEcdsaKeyPair extends Task
{
    public string $view = 'task-runner::tasks.generate-ecdsa-key-pair';

    public ?int $timeout = 600; // 10 minutes timeout for SSH key generation

    public function __construct(
        public string $privatePath,
        public int $bits = 256
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

        if (! in_array($this->bits, [256, 384, 521])) {
            $errors['bits'] = 'bits must be 256, 384, or 521.';
        }

        $script = $this->renderScript();
        if (strpos($script, 'ssh-keygen -t ecdsa') === false) {
            $errors['script'] = 'Script must contain ssh-keygen -t ecdsa command.';
        }

        if (! empty($errors)) {
            throw TaskValidationException::withErrors($errors);
        }
    }
}
