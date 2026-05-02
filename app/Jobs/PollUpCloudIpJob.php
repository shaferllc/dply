<?php

namespace App\Jobs;

use App\Jobs\Concerns\DispatchesServerProvisionJob;
use App\Jobs\Concerns\HandlesFakeCloudPoll;
use App\Models\Server;
use App\Services\UpCloudService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollUpCloudIpJob implements ShouldQueue
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

        $upcloud = new UpCloudService($credential);
        $server = $upcloud->getServer($this->server->provider_id);
        $state = $server['state'] ?? '';

        if ($state === 'started') {
            $ip = UpCloudService::getPublicIp($server);
            if ($ip) {
                $this->server->update([
                    'ip_address' => $ip,
                    'status' => Server::STATUS_READY,
                ]);

                $this->dispatchServerProvisionIfNeeded($this->server);

                return;
            }
        }

        $this->release($this->backoff);
    }
}
