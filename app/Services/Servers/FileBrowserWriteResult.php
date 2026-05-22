<?php

declare(strict_types=1);

namespace App\Services\Servers;

class FileBrowserWriteResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $conflictReason,
        public readonly string $newSha256,
        public readonly int $newMtime,
    ) {}

    public function conflict(): bool
    {
        return ! $this->ok && $this->conflictReason === 'CONFLICT';
    }

    public function missing(): bool
    {
        return ! $this->ok && $this->conflictReason === 'MISSING';
    }
}
