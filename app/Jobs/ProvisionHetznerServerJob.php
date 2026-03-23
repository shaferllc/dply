<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\HetznerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use phpseclib3\Crypt\RSA;

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

        $hetzner = new HetznerService($credential);

        $key = RSA::createKey(2048);
        $privateKey = $key->toString('OpenSSH');
        $publicKey = $key->getPublicKey()->toString('OpenSSH');

        $image = config('services.hetzner.default_image', 'ubuntu-24.04');

        $id = $hetzner->createInstance(
            name: $this->server->name,
            location: $this->server->region,
            serverType: $this->server->size,
            image: $image,
            sshPublicKeys: [$publicKey],
            userData: ''
        );

        $this->server->update([
            'provider_id' => (string) $id,
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => $privateKey,
            'ssh_user' => config('services.hetzner.ssh_user', 'root'),
        ]);

        PollHetznerIpJob::dispatch($this->server)->delay(now()->addSeconds(15));
    }
}
