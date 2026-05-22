<?php

declare(strict_types=1);

namespace Tests\Support\Imports;

use App\Contracts\RemoteShell;
use App\Models\ImportServerMigration;
use App\Services\Imports\SourceSshConnectionFactory;

/**
 * Test double for SourceSshConnectionFactory — returns the RecordingShell
 * supplied in the constructor regardless of which migration's source is
 * asked for. Lets handler tests pin Ploi-side or Forge-side command
 * pipelines without opening real sockets.
 */
final class FakeSourceSshConnectionFactory extends SourceSshConnectionFactory
{
    public function __construct(private RecordingShell $shell) {}

    public function forMigration(ImportServerMigration $migration): RemoteShell
    {
        return $this->shell;
    }
}
