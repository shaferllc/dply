<?php

declare(strict_types=1);

namespace App\Support\Mail\Guided;

/**
 * Whether a site can use a {@see GuidedMailProvider}, plus the list of its
 * domains eligible to send from (primary + brand aliases; preview/tenant
 * hostnames are excluded — you don't send brand mail from those).
 */
final readonly class GuidedMailGate
{
    /** @param  list<string>  $domains */
    public function __construct(
        public bool $eligible,
        public ?string $reason = null,
        public array $domains = [],
    ) {}

    public static function ineligible(string $reason): self
    {
        return new self(false, $reason, []);
    }
}
