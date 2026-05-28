<?php

declare(strict_types=1);

namespace App\Services\Deploy;

/**
 * Request/job-scoped override for SSH private key during a deploy that uses
 * ephemeral server credentials instead of the server's operational key.
 */
final class EphemeralDeployCredentialContext
{
    private ?string $privateKey = null;

    public function setPrivateKey(string $privateKey): void
    {
        $this->privateKey = $privateKey;
    }

    public function clear(): void
    {
        $this->privateKey = null;
    }

    public function hasPrivateKey(): bool
    {
        return is_string($this->privateKey) && trim($this->privateKey) !== '';
    }

    public function privateKey(): ?string
    {
        return $this->privateKey;
    }
}
