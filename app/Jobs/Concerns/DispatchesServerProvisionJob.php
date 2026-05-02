<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\Server;
use App\Services\Servers\ServerProvisionDispatch;

trait DispatchesServerProvisionJob
{
    protected function dispatchServerProvisionIfNeeded(Server $server): void
    {
        ServerProvisionDispatch::afterReady($server);
    }
}
