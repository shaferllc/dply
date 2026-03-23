<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\EquinixMetalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use phpseclib3\Crypt\RSA;

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

        $metal = new EquinixMetalService($credential);

        $key = RSA::createKey(2048);
        $privateKey = $key->toString('OpenSSH');
        $publicKey = $key->getPublicKey()->toString('OpenSSH');

        $sshKeyLabel = 'dply-'.$this->server->id.'-'.substr(uniqid(), -6);
        $sshKeyId = $metal->createSshKey($sshKeyLabel, $publicKey);

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
            'ssh_private_key' => $privateKey,
            'ssh_user' => config('services.equinix_metal.ssh_user', 'root'),
        ]);

        PollEquinixMetalIpJob::dispatch($this->server)->delay(now()->addSeconds(30));
    }
}
