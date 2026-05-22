<?php

declare(strict_types=1);

namespace Tests\Support\Imports;

use App\Models\Server;
use App\Services\SshConnection;
use App\Services\SshConnectionFactory;

/**
 * Test double for App\Services\SshConnectionFactory — returns the
 * RecordingShell supplied in the constructor regardless of the Server.
 */
final class FakeSshConnectionFactory extends SshConnectionFactory
{
    public function __construct(private RecordingShell $shell) {}

    public function forServer(Server $server): SshConnection
    {
        return $this->shell;
    }
}
