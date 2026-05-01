<?php

namespace App\Jobs;

use App\Actions\Servers\ApplyFakeCloudProvisionAsReady;
use App\Models\Server;
use App\Services\Servers\ServerProvisionSshKeyMaterial;
use App\Services\VultrService;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionVultrServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        $credential = $this->server->providerCredential;
        if (! $credential || $credential->provider !== 'vultr') {
            $this->server->update(['status' => Server::STATUS_ERROR]);

            return;
        }

        if (FakeCloudProvision::shouldInterceptVmProvision($this->server)) {
            ApplyFakeCloudProvisionAsReady::run($this->server);

            return;
        }

        $vultr = new VultrService($credential);

        $keys = app(ServerProvisionSshKeyMaterial::class)->generate();

        $sshKeyName = 'dply-'.$this->server->id.'-'.substr(uniqid(), -6);
        $sshKeyId = $vultr->createSshKey($sshKeyName, $keys['recovery_public_key']);

        $osId = (int) config('services.vultr.default_os_id', 2152);

        $id = $vultr->createInstance(
            region: $this->server->region,
            plan: $this->server->size,
            osId: $osId,
            label: $this->server->name,
            sshKeyIds: [$sshKeyId]
        );

        $this->server->update([
            'provider_id' => $id,
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => $keys['recovery_private_key'],
            'ssh_recovery_private_key' => $keys['recovery_private_key'],
            'ssh_operational_private_key' => $keys['operational_private_key'],
            'ssh_user' => config('services.vultr.ssh_user', 'root'),
        ]);

        PollVultrIpJob::dispatch($this->server)->delay(now()->addSeconds(15));
    }
}
