<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Result of reading one file (metadata + optionally content).
 */
class FileBrowserFileRead
{
    public function __construct(
        public readonly string $path,
        public readonly int $size,
        public readonly int $mtime,
        public readonly string $sha256,
        public readonly string $mime,
        public readonly bool $isBinary,
        public readonly ?string $content = null,
        public readonly bool $contentTruncated = false,
    ) {}
}
