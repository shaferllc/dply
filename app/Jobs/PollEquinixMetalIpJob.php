<?php

namespace App\Jobs;

use App\Jobs\RunSetupScriptJob;
use App\Models\Server;
use App\Services\EquinixMetalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollEquinixMetalIpJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 40;

    public int $backoff = 20;

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

        $metal = new EquinixMetalService($credential);
        $device = $metal->getDevice($this->server->provider_id);
        $state = $device['state'] ?? '';

        if ($state === 'active') {
            $ip = EquinixMetalService::getPublicIp($device);
            if ($ip) {
                $this->server->update([
                    'ip_address' => $ip,
                    'status' => Server::STATUS_READY,
                ]);

                if ($this->server->setup_script_key && $this->server->setup_script_key !== 'none') {
                    RunSetupScriptJob::dispatch($this->server->fresh());
                }

                return;
            }
        }

        $this->release($this->backoff);
    }
}
