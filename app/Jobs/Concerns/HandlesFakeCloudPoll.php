<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\Server;
use App\Support\Servers\FakeCloudProvision;

trait HandlesFakeCloudPoll
{
    /**
     * Skip vendor polling for fake-local servers.
     *
     * Stack dispatch already ran from {@see ApplyFakeCloudProvisionAsReady}; do not enqueue again here.
     */
    protected function finishFakeCloudPollIfNeeded(Server $server): bool
    {
        if (! FakeCloudProvision::isFakeServer($server)) {
            return false;
        }

        return true;
    }
}
