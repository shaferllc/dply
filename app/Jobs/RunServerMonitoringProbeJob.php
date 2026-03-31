<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Servers\ServerMonitoringProbe;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunServerMonitoringProbeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $serverId,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function uniqueId(): string
    {
        return 'server-monitoring-probe:'.$this->serverId;
    }

    public function handle(ServerMonitoringProbe $probe): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server === null) {
            $this->clearPendingFlag();

            return;
        }

        $probe->probeAndStore($server->fresh());
        $this->clearPendingFlag();
    }

    public function failed(?Throwable $exception): void
    {
        $this->clearPendingFlag();
    }

    protected function clearPendingFlag(): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server === null) {
            return;
        }
        $meta = $server->meta ?? [];
        unset($meta['monitoring_probe_pending'], $meta['monitoring_probe_pending_at']);
        $server->update(['meta' => $meta]);
    }
}
