<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Support;

use App\Modules\Deploy\Contracts\RepositoryFiles;

/**
 * A checkout on this machine's filesystem — the serverless build path, and the
 * shape every existing detector test exercises.
 */
final class LocalRepositoryFiles implements RepositoryFiles
{
    public function __construct(private readonly string $workingDirectory) {}

    public function exists(string $path): bool
    {
        return is_file($this->absolute($path));
    }

    public function read(string $path): ?string
    {
        $absolute = $this->absolute($path);

        if (! is_file($absolute)) {
            return null;
        }

        $contents = file_get_contents($absolute);

        return $contents === false ? null : $contents;
    }

    private function absolute(string $path): string
    {
        return rtrim($this->workingDirectory, '/').'/'.ltrim($path, '/');
    }
}
