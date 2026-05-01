<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Jobs\RunSetupScriptJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Models\Server;

/**
 * Shared hook after a server has a public IP and SSH material (cloud poll or fake provision).
 */
final class ServerProvisionDispatch
{
    public static function afterReady(Server $server): void
    {
        $fresh = $server->fresh();
        if ($fresh && RunSetupScriptJob::shouldDispatch($fresh)) {
            WaitForServerSshReadyJob::dispatch($fresh);
        }
    }
}
