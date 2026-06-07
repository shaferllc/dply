<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\DispatchesServerProvisionJob;
use App\Jobs\Concerns\HandlesFakeCloudPoll;
use App\Models\Server;
use App\Services\OracleComputeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollOracleIpJob implements ShouldQueue
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

        $oracle = new OracleComputeService($credential);
        $instance = $oracle->getInstance($this->server->provider_id);
        $lifecycleState = strtoupper((string) ($instance['lifecycleState'] ?? ''));
        $ip = $oracle->getPublicIp($this->server->provider_id);

        if (in_array($lifecycleState, ['TERMINATED', 'TERMINATING'], true)) {
            $this->server->update(['status' => Server::STATUS_ERROR]);

            return;
        }

        if ($lifecycleState === 'RUNNING' && is_string($ip) && $ip !== '') {
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
