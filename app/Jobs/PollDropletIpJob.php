<?php

namespace App\Jobs;

use App\Jobs\Concerns\DispatchesServerProvisionJob;
use App\Jobs\Concerns\HandlesFakeCloudPoll;
use App\Models\PrivateNetwork;
use App\Models\Server;
use App\Services\DigitalOceanService;
use App\Services\Servers\ServerPrivateNetworkRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollDropletIpJob implements ShouldQueue
{
    use DispatchesServerProvisionJob;
    use HandlesFakeCloudPoll;
    use Queueable;

    public int $tries = 180;

    public int $backoff = 5;

    public function __construct(
        public Server $server
    ) {
        $this->onQueue(config('server_provision.queue', 'dply'));
    }

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

        $do = new DigitalOceanService($credential);
        $droplet = $do->getDroplet((int) $this->server->provider_id);
        $ip = DigitalOceanService::getDropletPublicIp($droplet);

        if ($ip) {
            $updates = [
                'ip_address' => $ip,
                'status' => Server::STATUS_READY,
            ];

            // Capture the VPC/private IP at provision time. Don't clobber a
            // value already on the row (e.g. a manually-set internal IP).
            $privateIp = DigitalOceanService::getDropletPrivateIp($droplet);
            if ($privateIp !== null && blank($this->server->private_ip_address)) {
                $updates['private_ip_address'] = $privateIp;
            }

            $this->server->update($updates);

            $this->recordPrivateNetwork($do, $droplet);

            $this->dispatchServerProvisionIfNeeded($this->server);

            return;
        }

        $this->release($this->backoff);
    }

    /**
     * Record the droplet's VPC as a PrivateNetwork and link the server to it, so
     * same-VPC peers resolve to the same network for private reachability. The
     * VPC UUID is the identity; the CIDR (looked up via listVpcs) is best-effort.
     *
     * @param  array<string, mixed>  $droplet
     */
    private function recordPrivateNetwork(DigitalOceanService $do, array $droplet): void
    {
        $vpcUuid = DigitalOceanService::getDropletVpcUuid($droplet);
        if ($vpcUuid === null) {
            return;
        }

        $ipRange = null;
        $name = null;
        try {
            foreach ($do->listVpcs() as $vpc) {
                if (($vpc['id'] ?? '') === $vpcUuid) {
                    $ipRange = ($vpc['ip_range'] ?? '') ?: null;
                    $name = ($vpc['name'] ?? '') ?: null;
                    break;
                }
            }
        } catch (\Throwable) {
            // VPC listing is best-effort — recording by UUID alone still enables
            // FK-match peering; the CIDR just powers the range-membership fallback.
        }

        app(ServerPrivateNetworkRecorder::class)->record(
            $this->server,
            PrivateNetwork::PROVIDER_DO,
            $vpcUuid,
            $ipRange,
            $name,
        );
    }
}
