<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Jobs\RunSetupScriptJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Models\Server;

trait DispatchesServerProvisionJob
{
    protected function dispatchServerProvisionIfNeeded(Server $server): void
    {
        $fresh = $server->fresh();
        if ($fresh && RunSetupScriptJob::shouldDispatch($fresh)) {
            WaitForServerSshReadyJob::dispatch($fresh);
        }
    }
}
