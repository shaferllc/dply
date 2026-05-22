<?php

namespace Tests\Support;

use App\Models\Server;
use App\Services\SshConnection;
use App\Services\SshConnectionFactory;

/**
 * Swappable SshConnectionFactory for feature tests — never opens a real socket.
 */
final class FakeSshConnectionFactory extends SshConnectionFactory
{
    public function __construct(
        private readonly FakeRemoteShell $shell = new FakeRemoteShell,
    ) {}

    public function forServer(Server $server): SshConnection
    {
        return $this->shell;
    }

    public function recoveryForServer(Server $server): SshConnection
    {
        return $this->shell;
    }
}
