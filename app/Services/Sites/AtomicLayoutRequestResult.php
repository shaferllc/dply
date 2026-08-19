<?php

declare(strict_types=1);

namespace App\Services\Sites;

final readonly class AtomicLayoutRequestResult
{
    public function __construct(
        public bool $ok,
        public string $status,
        public string $message,
    ) {}

    public static function queued(string $message): self
    {
        return new self(true, 'queued', $message);
    }

    public static function armed(string $message): self
    {
        return new self(true, 'armed', $message);
    }

    public static function noop(string $message): self
    {
        return new self(true, 'noop', $message);
    }

    public static function refused(string $message): self
    {
        return new self(false, 'refused', $message);
    }

    public static function needsConfirm(string $message): self
    {
        return new self(false, 'needs_confirm', $message);
    }
}
