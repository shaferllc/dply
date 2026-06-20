<?php

declare(strict_types=1);

namespace App\Support\Mail\Guided;

/**
 * Result of the DNS pre-flight: which authentication records dply could see in
 * the zone. Soft signal — half-finished onboarding can leave records present
 * but sending still broken, which is why {@see GuidedMailProvider::verify()}
 * (a real send) is the authoritative check.
 */
final readonly class GuidedMailRecordStatus
{
    public function __construct(
        public bool $spf,
        public bool $dkim,
        public bool $dmarc,
        public ?string $detail = null,
    ) {}

    public function allPresent(): bool
    {
        return $this->spf && $this->dkim && $this->dmarc;
    }

    public static function unreadable(string $detail): self
    {
        return new self(false, false, false, $detail);
    }
}
