<?php

namespace App\Jobs;

use App\Enums\ServerProvider;
use App\Jobs\Concerns\DispatchesServerProvisionJob;
use App\Jobs\Concerns\HandlesFakeCloudPoll;
use App\Models\PrivateNetwork;
use App\Models\Server;
use App\Modules\Cloud\Services\VultrService;
use App\Services\Servers\ServerPrivateNetworkRecorder;
use App\Support\Servers\ServerHostingPlatformContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollVultrIpJob implements ShouldQueue
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
        $vultr = $this->resolveVultrClient();
        if ($vultr === null) {
            $this->server->update(['status' => Server::STATUS_ERROR]);

            return;
        }

        if ($this->finishFakeCloudPollIfNeeded($this->server)) {
            return;
        }

        $instance = $vultr->getInstance($this->server->provider_id);
        $ip = VultrService::getPublicIp($instance);

        if ($ip) {
            $updates = [
                'ip_address' => $ip,
                'status' => Server::STATUS_READY,
            ];

            // Vultr only has a private IP when an instance is attached to a VPC.
            $privateIp = VultrService::getPrivateIp($instance);
            if ($privateIp !== null && blank($this->server->private_ip_address)) {
                $updates['private_ip_address'] = $privateIp;
            }

            $this->server->update($updates);

            // Record the attached VPC (if any) so same-VPC peers can reach it.
            $vpcId = VultrService::getInstanceVpcId($instance);
            if ($vpcId !== null) {
                app(ServerPrivateNetworkRecorder::class)->record(
                    $this->server,
                    PrivateNetwork::PROVIDER_VULTR,
                    $vpcId,
                    VultrService::getInstanceVpcRange($instance),
                );
            }

            $this->dispatchServerProvisionIfNeeded($this->server);

            return;
        }

        $this->release($this->backoff);
    }

    /**
     * BYO servers use the linked credential; managed (dply-hosted) Vultr VMs
     * have no provider_credential_id and must use the platform token.
     */
    private function resolveVultrClient(): ?VultrService
    {
        $credential = $this->server->providerCredential;
        if ($credential !== null && $credential->provider === 'vultr') {
            return new VultrService($credential);
        }

        if (! $this->server->usesManagedHosting()) {
            return null;
        }

        $platform = ServerHostingPlatformContext::forOrg($this->server->organization);
        if ($platform->provider !== ServerProvider::Vultr || ! $platform->configured()) {
            return null;
        }

        return $platform->vultr();
    }
}
