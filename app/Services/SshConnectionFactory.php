<?php

namespace App\Services;

use App\Contracts\RemoteShell;
use App\Models\Server;

class SshConnectionFactory
{
    /**
     * Build an SSH connection for a server.
     *
     * Returns the concrete {@see SshConnection} (a {@see RemoteShell})
     * so callers needing connection-specific methods like
     * execWithCallbackAndExit() get them; routing construction through this
     * factory keeps the connection swappable in tests via the container.
     */
    public function forServer(Server $server): SshConnection
    {
        return new SshConnection($server);
    }

    /**
     * Build an SSH connection that logs in as root with the recovery
     * credential — used by provisioners that need privileged writes.
     */
    public function recoveryForServer(Server $server): SshConnection
    {
        return new SshConnection($server, 'root', SshConnection::ROLE_RECOVERY);
    }
}
