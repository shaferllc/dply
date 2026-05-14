<?php

declare(strict_types=1);

namespace Tests\Support\Imports;

use App\Contracts\RemoteShell;
use App\Models\ImportServerMigration;
use App\Services\Imports\Ploi\PloiSshConnectionFactory;

/**
 * Test double for PloiSshConnectionFactory — returns the RecordingShell
 * supplied in the constructor regardless of which migration is asked for.
 * Lets handler tests pin Ploi-side command pipelines without a real SSH
 * endpoint.
 */
final class FakePloiSshConnectionFactory extends PloiSshConnectionFactory
{
    public function __construct(private RecordingShell $shell) {}

    public function forMigration(ImportServerMigration $migration): RemoteShell
    {
        return $this->shell;
    }
}
