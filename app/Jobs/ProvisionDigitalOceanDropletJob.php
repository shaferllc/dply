<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\DigitalOceanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use phpseclib3\Crypt\RSA;

class ProvisionDigitalOceanDropletJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        $credential = $this->server->providerCredential;
        if (! $credential || $credential->provider !== 'digitalocean') {
            $this->server->update(['status' => Server::STATUS_ERROR]);

            return;
        }

        $do = new DigitalOceanService($credential);

        // Generate SSH key pair for this server
        $key = RSA::createKey(2048);
        $privateKey = $key->toString('OpenSSH');
        $publicKey = $key->getPublicKey()->toString('OpenSSH');

        $keyName = 'dply-'.$this->server->name.'-'.Str::random(6);
        $doKey = $do->addSshKey($keyName, $publicKey);
        $sshKeyId = $doKey['id'] ?? $doKey['fingerprint'] ?? null;
        if ($sshKeyId === null) {
            $this->server->update(['status' => Server::STATUS_ERROR]);

            return;
        }

        $image = config('services.digitalocean.default_image', 'ubuntu-24-04-x64');

        $droplet = $do->createDroplet(
            name: $this->server->name,
            region: $this->server->region,
            size: $this->server->size,
            image: $image,
            sshKeyIds: [$sshKeyId],
            ipv6: false,
            userData: ''
        );

        $this->server->update([
            'provider_id' => (string) ($droplet['id'] ?? ''),
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => $privateKey,
            'ssh_user' => config('services.digitalocean.ssh_user', 'root'),
        ]);

        PollDropletIpJob::dispatch($this->server)->delay(now()->addSeconds(15));
    }
}
