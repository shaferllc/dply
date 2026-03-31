<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner;

use InvalidArgumentException;

class FakeTask
{
    public function __construct(
        public readonly string $taskClass,
        public readonly ProcessOutput $processOutput
    ) {
        if (trim($taskClass) === '') {
            throw new InvalidArgumentException('The task class cannot be empty.');
        }
    }
}
