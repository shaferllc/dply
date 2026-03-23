<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\ScalewayService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use phpseclib3\Crypt\RSA;

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

        $scw = new ScalewayService($credential);

        $key = RSA::createKey(2048);
        $privateKey = $key->toString('OpenSSH');
        $publicKey = $key->getPublicKey()->toString('OpenSSH');
        $tagValue = 'AUTHORIZED_KEY='.str_replace(' ', '_', trim($publicKey));

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
            'ssh_private_key' => $privateKey,
            'ssh_user' => config('services.scaleway.ssh_user', 'root'),
        ]);

        PollScalewayIpJob::dispatch($this->server)->delay(now()->addSeconds(20));
    }
}
