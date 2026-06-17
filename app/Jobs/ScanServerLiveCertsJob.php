<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Services\Servers\WebserverCertsAggregator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs the cross-engine on-disk TLS certificate sweep over SSH and caches the
 * result, off the request thread. The webserver Health tab, the server cert
 * inventory, and a site's Caddy-managed cert card all dispatch this and poll
 * {@see WebserverCertsAggregator::cached()} for the result, so the 20s SSH probe
 * never runs inside a Livewire/HTTP request.
 */
class ScanServerLiveCertsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 90;

    public int $tries = 1;

    public function __construct(public string $serverId)
    {
        $queue = config('server_manage.remote_task_queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function handle(WebserverCertsAggregator $aggregator): void
    {
        $server = Server::find($this->serverId);
        if ($server === null) {
            $aggregator->cacheUnreadable($this->serverId);

            return;
        }

        $aggregator->scanAndCache($server);
    }

    /** Resolve a polling UI to the SSH-error state (and clear in-flight) after a hard failure. */
    public function failed(\Throwable $e): void
    {
        app(WebserverCertsAggregator::class)->cacheUnreadable($this->serverId);
    }
}
