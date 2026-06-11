<?php

declare(strict_types=1);

namespace App\Services\Secrets;

/**
 * Job-scoped holder for a customer-held org age identity supplied for ONE unit
 * of work (a manual env push or a deploy) and never persisted. The deploy job
 * sets it from a pulled cache token for the duration of the deploy so the env
 * push deep inside the deployer can decrypt customer-held escrowed secrets; it
 * is cleared in the job's finally.
 *
 * Bound as a container singleton — a queue worker reuses its container across
 * jobs, so callers MUST {@see forget()} after use to avoid leaking the identity
 * into the next job.
 */
class EphemeralSecretIdentityContext
{
    private ?string $identity = null;

    public function set(?string $identity): void
    {
        $this->identity = ($identity !== null && trim($identity) !== '') ? $identity : null;
    }

    public function get(): ?string
    {
        return $this->identity;
    }

    public function forget(): void
    {
        $this->identity = null;
    }
}
