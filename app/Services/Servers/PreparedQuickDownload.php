<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;

/**
 * A staged quick-download artifact: the remote file to stream, its measured
 * size (already validated against the cap), and how to fetch + name it.
 *
 * @see QuickDownloadStreamer
 */
final class PreparedQuickDownload
{
    public function __construct(
        public readonly Server $server,
        public readonly string $remotePath,
        public readonly int $bytes,
        public readonly string $filename,
        public readonly string $mime,
        public readonly bool $useRoot,
        public readonly bool $cleanup,
    ) {}
}
