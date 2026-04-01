<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\LinodeService;
use App\Services\Servers\ServerProvisionSshKeyMaterial;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionLinodeServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        $credential = $this->server->providerCredential;
        if (! $credential || ! in_array($credential->provider, ['linode', 'akamai'], true)) {
            $this->server->update(['status' => Server::STATUS_ERROR]);

            return;
        }

        $linode = new LinodeService($credential);

        $keys = app(ServerProvisionSshKeyMaterial::class)->generate();

        $image = config('services.linode.default_image', 'linode/ubuntu24.04');

        $id = $linode->createInstance(
            label: $this->server->name,
            region: $this->server->region,
            type: $this->server->size,
            image: $image,
            authorizedKeys: [$keys['recovery_public_key']]
        );

        $this->server->update([
            'provider_id' => (string) $id,
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => $keys['recovery_private_key'],
            'ssh_recovery_private_key' => $keys['recovery_private_key'],
            'ssh_operational_private_key' => $keys['operational_private_key'],
            'ssh_user' => config('services.linode.ssh_user', 'root'),
        ]);

        PollLinodeIpJob::dispatch($this->server)->delay(now()->addSeconds(15));
    }
}
