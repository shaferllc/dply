<?php

declare(strict_types=1);

namespace App\Services\Imports\Ploi;

use App\Contracts\RemoteShell;
use App\Models\ImportServerMigration;

/**
 * Resolves a RemoteShell connected to the source Ploi server for a given
 * migration. Default impl returns PloiSshConnection::forMigration; tests
 * swap the container binding to inject a recording fake.
 *
 * Symmetric in role to App\Services\SshConnectionFactory (which targets
 * dply Servers) so handlers can DI-inject both factories and the test
 * layer mocks each side independently.
 */
class PloiSshConnectionFactory
{
    public function forMigration(ImportServerMigration $migration): RemoteShell
    {
        return PloiSshConnection::forMigration($migration);
    }
}
