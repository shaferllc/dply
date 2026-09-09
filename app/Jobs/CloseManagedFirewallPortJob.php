<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Services\Servers\ManagedFirewallPort;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Revoke every managed UFW rule in a group, off the request path.
 *
 * {@see ManagedFirewallPort::closeAll()} talks to the box over SSH, which must
 * never run during a render (PHP's 30s max_execution_time — see CLAUDE.md), so
 * teardown that is triggered by a model event or a UI action goes through here.
 *
 * Deliberately takes a server id + group tag rather than a model: the feature
 * row that owned the port is usually being deleted, so by the time this runs
 * there may be nothing left to load.
 */
class CloseManagedFirewallPortJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 2;

    public function __construct(
        public string $serverId,
        public string $groupTag,
    ) {}

    public function handle(ManagedFirewallPort $ports): void
    {
        $server = Server::query()->find($this->serverId);

        if ($server === null) {
            // Server is gone; its firewall went with it.
            return;
        }

        $ports->closeAll($server, $this->groupTag);
        $ports->close($server, $this->groupTag);
    }
}
