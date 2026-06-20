<?php

declare(strict_types=1);

namespace App\Support\Mail\Guided;

/** Outcome of the control-plane verification send. */
final readonly class GuidedMailVerifyResult
{
    public function __construct(
        public bool $ok,
        public ?string $error = null,
    ) {}

    public static function pass(): self
    {
        return new self(true);
    }

    public static function fail(string $error): self
    {
        return new self(false, $error);
    }
}
