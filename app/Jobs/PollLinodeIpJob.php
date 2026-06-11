<?php

namespace App\Jobs;

use App\Jobs\Concerns\DispatchesServerProvisionJob;
use App\Jobs\Concerns\HandlesFakeCloudPoll;
use App\Models\Server;
use App\Services\LinodeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollLinodeIpJob implements ShouldQueue
{
    use DispatchesServerProvisionJob;
    use HandlesFakeCloudPoll;
    use Queueable;

    public int $tries = 180;

    public int $backoff = 5;

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

        $linode = new LinodeService($credential);
        $instance = $linode->getInstance((int) $this->server->provider_id);
        $ip = LinodeService::getPublicIp($instance);

        if ($ip) {
            $updates = [
                'ip_address' => $ip,
                'status' => Server::STATUS_READY,
            ];

            // Capture Linode's private IP for reference, but DO NOT record a
            // PrivateNetwork from it: Linode's legacy private address (192.168/17)
            // is shared across EVERY Linode the account owns in the datacenter — it
            // is not an isolated network, so treating it as one would falsely make
            // unrelated Linodes "reachable" peers (exactly the over-bridging we're
            // eliminating). Isolated Linode VPCs need the VPC config-interface API;
            // that integration is a follow-up.
            $privateIp = LinodeService::getPrivateIp($instance);
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
