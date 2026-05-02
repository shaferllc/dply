<?php

namespace App\Jobs;

use App\Actions\Servers\ApplyFakeCloudProvisionAsReady;
use App\Models\Server;
use App\Services\ScalewayService;
use App\Services\Servers\ServerProvisionSshKeyMaterial;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionScalewayServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        $credential = $this->server->providerCredential;
        if (! $credential || $credential->provider !== 'scaleway') {
            $this->server->update(['status' => Server::STATUS_ERROR]);

            return;
        }

        if (FakeCloudProvision::shouldInterceptVmProvision($this->server)) {
            ApplyFakeCloudProvisionAsReady::run($this->server);

            return;
        }

        $scw = new ScalewayService($credential);

        $keys = app(ServerProvisionSshKeyMaterial::class)->generate();
        $tagValue = 'AUTHORIZED_KEY='.str_replace(' ', '_', trim($keys['recovery_public_key']));

        $image = config('services.scaleway.default_image', 'ubuntu_jammy');

        $id = $scw->createServer(
            zone: $this->server->region,
            name: $this->server->name,
            commercialType: $this->server->size,
            image: $image,
            tags: [$tagValue]
        );

        $this->server->update([
            'provider_id' => $id,
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => $keys['recovery_private_key'],
            'ssh_recovery_private_key' => $keys['recovery_private_key'],
            'ssh_operational_private_key' => $keys['operational_private_key'],
            'ssh_user' => config('services.scaleway.ssh_user', 'root'),
        ]);

        PollScalewayIpJob::dispatch($this->server)->delay(now()->addSeconds(20));
    }
}
