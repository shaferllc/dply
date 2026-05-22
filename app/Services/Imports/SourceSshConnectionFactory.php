<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Contracts\RemoteShell;
use App\Models\ImportServerMigration;
use App\Services\Imports\Forge\ForgeSshConnection;
use App\Services\Imports\Ploi\PloiSshConnection;
use RuntimeException;

/**
 * Returns the right source-side SSH shell for an ImportServerMigration —
 * Ploi shells in as `ploi`, Forge shells in as `forge`. Lets the SSH-using
 * handlers stay source-agnostic.
 *
 * Tests substitute a fake via the container binding — see
 * tests/Support/Imports/FakeSourceSshConnectionFactory for the recording
 * double used by the handler suite.
 */
class SourceSshConnectionFactory
{
    public function forMigration(ImportServerMigration $migration): RemoteShell
    {
        return match ($migration->source) {
            'ploi' => PloiSshConnection::forMigration($migration),
            'forge' => ForgeSshConnection::forMigration($migration),
            default => throw new RuntimeException(
                sprintf('No SSH connection registered for source %s', $migration->source)
            ),
        };
    }
}
