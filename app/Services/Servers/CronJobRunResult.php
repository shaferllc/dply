<?php

declare(strict_types=1);

namespace App\Services\Servers;

final readonly class CronJobRunResult
{
    public function __construct(
        public string $output,
        public ?int $exitCode,
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }
}
