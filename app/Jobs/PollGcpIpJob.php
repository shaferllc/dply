<?php

namespace App\Jobs;

use App\Jobs\Concerns\DispatchesServerProvisionJob;
use App\Jobs\Concerns\HandlesFakeCloudPoll;
use App\Models\Server;
use App\Services\GcpComputeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollGcpIpJob implements ShouldQueue
{
    use DispatchesServerProvisionJob;
    use HandlesFakeCloudPoll;
    use Queueable;

    public int $tries = 60;

    public int $backoff = 15;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        $credential = $this->server->providerCredential;
        if (! $credential) {
            $this->server->update(['status' => Server::STATUS_ERROR]);

            return;
        }

        if ($this->finishFakeCloudPollIfNeeded($this->server)) {
            return;
        }

        $gcp = new GcpComputeService($credential);
        $instance = $gcp->getInstance($this->server->region, $this->server->provider_id);
        $ip = GcpComputeService::getPublicIp($instance);
        $status = strtoupper((string) ($instance['status'] ?? ''));

        if (in_array($status, ['TERMINATED', 'STOPPING', 'SUSPENDING', 'SUSPENDED'], true)) {
            $this->server->update(['status' => Server::STATUS_ERROR]);

            return;
        }

        if ($status === 'RUNNING' && $ip) {
            $this->server->update([
                'ip_address' => $ip,
                'status' => Server::STATUS_READY,
            ]);

            $this->dispatchServerProvisionIfNeeded($this->server);

            return;
        }

        $this->release($this->backoff);
    }
}
