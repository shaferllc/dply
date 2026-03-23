<?php

namespace App\Services;

use App\Contracts\RemoteShell;
use App\Models\Server;

class SshConnectionFactory
{
    public function forServer(Server $server): RemoteShell
    {
        return new SshConnection($server);
    }
}
