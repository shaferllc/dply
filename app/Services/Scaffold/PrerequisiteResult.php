<?php

declare(strict_types=1);

namespace App\Services\Scaffold;

/**
 * Tri-state outcome of a single binary-prerequisite check + install.
 *
 * Used by {@see ScaffoldPrerequisites} to communicate to the scaffold
 * pipeline whether step 0 was a no-op (already there), did real work
 * (installed it now), or failed (pipeline must abort).
 */
final class PrerequisiteResult
{
    public const STATE_PRESENT = 'present';

    public const STATE_INSTALLED = 'installed';

    public const STATE_FAILED = 'failed';

    private function __construct(
        public readonly string $binary,
        public readonly string $state,
        public readonly ?string $error = null,
    ) {}

    public static function alreadyPresent(string $binary): self
    {
        return new self($binary, self::STATE_PRESENT);
    }

    public static function installed(string $binary): self
    {
        return new self($binary, self::STATE_INSTALLED);
    }

    public static function failed(string $binary, string $error): self
    {
        return new self($binary, self::STATE_FAILED, $error);
    }

    public function ok(): bool
    {
        return $this->state !== self::STATE_FAILED;
    }
}
