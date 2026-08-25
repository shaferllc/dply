<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Support;

use App\Contracts\RemoteShell;
use App\Modules\Deploy\Contracts\RepositoryFiles;

/**
 * A checkout on a remote server, reached over SSH.
 *
 * Existence is answered from a single `find` taken on first use, not one SSH
 * round trip per lookup: the detector asks about ~30 marker paths, and doing
 * that serially over SSH would add seconds to every deploy. Reads are separate
 * (only a handful of manifest files are ever read) and memoised.
 */
final class RemoteRepositoryFiles implements RepositoryFiles
{
    /** @var array<string, true>|null */
    private ?array $listing = null;

    /** @var array<string, string|null> */
    private array $reads = [];

    public function __construct(
        private readonly RemoteShell $ssh,
        private readonly string $root,
        /** Depth to index. Marker files live at the root or one level down. */
        private readonly int $maxDepth = 3,
    ) {}

    public function exists(string $path): bool
    {
        return array_key_exists($this->normalize($path), $this->listing());
    }

    public function read(string $path): ?string
    {
        $path = $this->normalize($path);

        if (array_key_exists($path, $this->reads)) {
            return $this->reads[$path];
        }

        if (! $this->exists($path)) {
            return $this->reads[$path] = null;
        }

        $raw = $this->ssh->exec(
            'cat '.escapeshellarg(rtrim($this->root, '/').'/'.$path).' 2>/dev/null',
            60
        );

        $raw = trim($raw);

        return $this->reads[$path] = $raw === '' ? null : $raw;
    }

    /** @return array<string, true> */
    private function listing(): array
    {
        if ($this->listing !== null) {
            return $this->listing;
        }

        $root = rtrim($this->root, '/');

        // -printf is GNU-only; %P prints the path relative to the root, which is
        // exactly the key the detector asks with. Prune the directories that are
        // large and never hold marker files so the listing stays small.
        $out = $this->ssh->exec(sprintf(
            'find %s -maxdepth %d '
            .'\( -name .git -o -name node_modules -o -name vendor -o -name .next \) -prune -o '
            .'-type f -printf "%%P\\n" 2>/dev/null',
            escapeshellarg($root),
            $this->maxDepth,
        ), 60);

        $listing = [];
        foreach (preg_split('/\R/', $out) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $listing[$line] = true;
            }
        }

        return $this->listing = $listing;
    }

    private function normalize(string $path): string
    {
        return ltrim($path, '/');
    }
}
