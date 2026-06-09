<?php

namespace App\Jobs;

use App\Jobs\Concerns\DispatchesServerProvisionJob;
use App\Jobs\Concerns\HandlesFakeCloudPoll;
use App\Models\Server;
use App\Services\AwsEc2Service;
use App\Services\AwsEc2ServiceFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollAwsEc2IpJob implements ShouldQueue
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

        $aws = app(AwsEc2ServiceFactory::class)->make($credential, $this->server->region);
        $instances = $aws->describeInstances($this->server->provider_id);
        $state = AwsEc2Service::getState($instances);
        $ip = AwsEc2Service::getPublicIp($instances);

        if (in_array($state, ['terminated', 'shutting-down', 'stopped'], true)) {
            $this->server->update(['status' => Server::STATUS_ERROR]);

            return;
        }

        if ($state === 'running' && $ip) {
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
