<?php

declare(strict_types=1);

namespace App\Services\DeployContract;

final class DeployContractCheckResult
{
    public const STATUS_PASS = 'pass';

    public const STATUS_FAIL = 'fail';

    public const STATUS_SKIP = 'skip';

    public function __construct(
        public string $status,
        public string $message,
    ) {}

    public function passed(): bool
    {
        return $this->status === self::STATUS_PASS || $this->status === self::STATUS_SKIP;
    }
}
