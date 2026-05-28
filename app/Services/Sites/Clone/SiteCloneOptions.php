<?php

declare(strict_types=1);

namespace App\Services\Sites\Clone;

/**
 * Optional flags for clone / promote jobs.
 */
final readonly class SiteCloneOptions
{
    public function __construct(
        public bool $previewFirstPromote = false,
        public ?string $sourceProductionHostname = null,
    ) {}

    public function isPromote(): bool
    {
        return $this->previewFirstPromote && is_string($this->sourceProductionHostname) && $this->sourceProductionHostname !== '';
    }
}
