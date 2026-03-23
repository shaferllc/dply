<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\LinodeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use phpseclib3\Crypt\RSA;

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

        $key = RSA::createKey(2048);
        $privateKey = $key->toString('OpenSSH');
        $publicKey = $key->getPublicKey()->toString('OpenSSH');

        $image = config('services.linode.default_image', 'linode/ubuntu24.04');

        $id = $linode->createInstance(
            label: $this->server->name,
            region: $this->server->region,
            type: $this->server->size,
            image: $image,
            authorizedKeys: [$publicKey]
        );

        $this->server->update([
            'provider_id' => (string) $id,
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => $privateKey,
            'ssh_user' => config('services.linode.ssh_user', 'root'),
        ]);

        PollLinodeIpJob::dispatch($this->server)->delay(now()->addSeconds(15));
    }
}
