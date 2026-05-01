<?php

namespace App\Jobs;

use App\Actions\Servers\ApplyFakeCloudProvisionAsReady;
use App\Models\Server;
use App\Services\HetznerService;
use App\Services\Servers\ServerProvisionSshKeyMaterial;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionHetznerServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        $credential = $this->server->providerCredential;
        if (! $credential || $credential->provider !== 'hetzner') {
            $this->server->update(['status' => Server::STATUS_ERROR]);

            return;
        }

        if (FakeCloudProvision::shouldInterceptVmProvision($this->server)) {
            ApplyFakeCloudProvisionAsReady::run($this->server);

            return;
        }

        $hetzner = new HetznerService($credential);

        $keys = app(ServerProvisionSshKeyMaterial::class)->generate();

        $image = config('services.hetzner.default_image', 'ubuntu-24.04');

        $id = $hetzner->createInstance(
            name: $this->server->name,
            location: $this->server->region,
            serverType: $this->server->size,
            image: $image,
            sshPublicKeys: [$keys['recovery_public_key']],
            userData: ''
        );

        $this->server->update([
            'provider_id' => (string) $id,
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => $keys['recovery_private_key'],
            'ssh_recovery_private_key' => $keys['recovery_private_key'],
            'ssh_operational_private_key' => $keys['operational_private_key'],
            'ssh_user' => config('services.hetzner.ssh_user', 'root'),
        ]);

        PollHetznerIpJob::dispatch($this->server)->delay(now()->addSeconds(15));
    }
}
