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
            $updates = [
                'ip_address' => $ip,
                'status' => Server::STATUS_READY,
            ];

            // Capture the private_net IP at provision time. Don't clobber a
            // value already on the row (e.g. a manually-set internal IP).
            $privateIp = HetznerService::getPrivateIp($instance);
            if ($privateIp !== null && blank($this->server->private_ip_address)) {
                $updates['private_ip_address'] = $privateIp;
            }

            $this->server->update($updates);

            $this->dispatchServerProvisionIfNeeded($this->server);

            return;
        }

        $this->release($this->backoff);
    }
}
