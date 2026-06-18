<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services\Manifest;

/**
 * Single process entry within a `dply.yaml` manifest.
 *
 * The manifest's `processes:` map keys (e.g. "web", "worker", "scheduler",
 * or any user-named custom type) become process names. Each value is either
 * a bare string (treated as the command, scale=1) or a map with explicit
 * `command:` and optional `scale:` fields.
 */
final readonly class DplyManifestProcess
{
    public function __construct(
        public string $name,
        public string $command,
        public int $scale = 1,
    ) {}
}
