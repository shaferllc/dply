<?php

namespace App\Jobs;

use App\Actions\Servers\ApplyFakeCloudProvisionAsReady;
use App\Models\Server;
use App\Services\EquinixMetalService;
use App\Services\Servers\ServerProvisionSshKeyMaterial;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionEquinixMetalServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        $credential = $this->server->providerCredential;
        if (! $credential || $credential->provider !== 'equinix_metal') {
            $this->server->update(['status' => Server::STATUS_ERROR]);

            return;
        }

        if (FakeCloudProvision::shouldInterceptVmProvision($this->server)) {
            ApplyFakeCloudProvisionAsReady::run($this->server);

            return;
        }

        $metal = new EquinixMetalService($credential);

        $keys = app(ServerProvisionSshKeyMaterial::class)->generate();

        $sshKeyLabel = 'dply-'.$this->server->id.'-'.substr(uniqid(), -6);
        $sshKeyId = $metal->createSshKey($sshKeyLabel, $keys['recovery_public_key']);

        $os = config('services.equinix_metal.default_os', 'ubuntu_22_04');

        $id = $metal->createDevice(
            hostname: $this->server->name,
            plan: $this->server->size,
            operatingSystem: $os,
            metro: $this->server->region,
            sshKeyIds: [$sshKeyId]
        );

        $this->server->update([
            'provider_id' => $id,
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => $keys['recovery_private_key'],
            'ssh_recovery_private_key' => $keys['recovery_private_key'],
            'ssh_operational_private_key' => $keys['operational_private_key'],
            'ssh_user' => config('services.equinix_metal.ssh_user', 'root'),
        ]);

        PollEquinixMetalIpJob::dispatch($this->server)->delay(now()->addSeconds(30));
    }
}
