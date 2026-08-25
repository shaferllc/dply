<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Contracts;

/**
 * Read-only view of a repository checkout, wherever it lives.
 *
 * {@see \App\Modules\Deploy\Services\RepositoryRuntimeDetector} already knows how
 * to identify Laravel, Symfony, Next, Nuxt, Express, Django, Flask, FastAPI, Go
 * and the generic language cases — but it only ever read the LOCAL filesystem,
 * so VM deploys (whose checkout sits on the remote box) could not use it. That
 * led to a second, composer-only detector for VM deploys and a Node fallback
 * bolted onto it, with two vocabularies for the same frameworks.
 *
 * This port is the fix: one detector, two ways to reach the files.
 */
interface RepositoryFiles
{
    /** Whether a repo-relative path exists as a file. */
    public function exists(string $path): bool;

    /** Contents of a repo-relative file, or null when it is absent/unreadable. */
    public function read(string $path): ?string;
}
