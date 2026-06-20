<?php

declare(strict_types=1);

namespace App\Support\Mail\Guided;

/** A single human onboarding step shown in the guided panel. */
final readonly class GuidedMailStep
{
    public function __construct(
        public string $title,
        public string $body,
    ) {}
}
