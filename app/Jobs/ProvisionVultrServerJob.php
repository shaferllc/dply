<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\VultrService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use phpseclib3\Crypt\RSA;

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

        $vultr = new VultrService($credential);

        $key = RSA::createKey(2048);
        $privateKey = $key->toString('OpenSSH');
        $publicKey = $key->getPublicKey()->toString('OpenSSH');

        $sshKeyName = 'dply-'.$this->server->id.'-'.substr(uniqid(), -6);
        $sshKeyId = $vultr->createSshKey($sshKeyName, $publicKey);

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
            'ssh_private_key' => $privateKey,
            'ssh_user' => config('services.vultr.ssh_user', 'root'),
        ]);

        PollVultrIpJob::dispatch($this->server)->delay(now()->addSeconds(15));
    }
}
