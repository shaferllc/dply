<?php

namespace App\Services;

use App\Models\Server;

class SshConnectionFactory
{
    /**
     * Build an SSH connection for a server.
     *
     * Returns the concrete {@see SshConnection} (a {@see \App\Contracts\RemoteShell})
     * so callers needing connection-specific methods like
     * execWithCallbackAndExit() get them; routing construction through this
     * factory keeps the connection swappable in tests via the container.
     */
    public function forServer(Server $server): SshConnection
    {
        return new SshConnection($server);
    }
}
