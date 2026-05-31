<?php

namespace App\Jobs;

use App\Jobs\Concerns\DispatchesServerProvisionJob;
use App\Jobs\Concerns\HandlesFakeCloudPoll;
use App\Models\Server;
use App\Services\AzureComputeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollAzureIpJob implements ShouldQueue
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

        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $azureMeta = is_array($meta['azure'] ?? null) ? $meta['azure'] : [];
        $resourceGroup = (string) ($azureMeta['resource_group'] ?? '');
        $publicIpName = (string) ($azureMeta['pip_name'] ?? '');
        if ($resourceGroup === '' || $publicIpName === '') {
            $this->server->update(['status' => Server::STATUS_ERROR]);

            return;
        }

        $azure = new AzureComputeService($credential);
        $ip = $azure->getVmPublicIp($resourceGroup, $publicIpName);

        if ($ip !== null) {
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
