<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Result of listing one directory.
 */
class FileBrowserListing
{
    /**
     * @param  list<FileBrowserEntry>  $entries
     */
    public function __construct(
        public readonly string $path,
        public readonly array $entries,
        public readonly bool $truncated,
        public readonly int $totalCount,
        public readonly ?string $filter = null,
    ) {}
}
