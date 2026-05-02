<?php

namespace App\Jobs;

use App\Jobs\Concerns\DispatchesServerProvisionJob;
use App\Jobs\Concerns\HandlesFakeCloudPoll;
use App\Models\Server;
use App\Services\HetznerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollHetznerIpJob implements ShouldQueue
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

        $hetzner = new HetznerService($credential);
        $instance = $hetzner->getInstance((int) $this->server->provider_id);
        $ip = HetznerService::getPublicIp($instance);

        if ($ip) {
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
