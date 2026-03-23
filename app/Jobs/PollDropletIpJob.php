<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\DigitalOceanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollDropletIpJob implements ShouldQueue
{
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

        $do = new DigitalOceanService($credential);
        $droplet = $do->getDroplet((int) $this->server->provider_id);
        $ip = DigitalOceanService::getDropletPublicIp($droplet);

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

        $this->release($this->backoff);
    }
}
