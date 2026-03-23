<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\UpCloudService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use phpseclib3\Crypt\RSA;

class ProvisionUpCloudServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        $credential = $this->server->providerCredential;
        if (! $credential || $credential->provider !== 'upcloud') {
            $this->server->update(['status' => Server::STATUS_ERROR]);

            return;
        }

        $upcloud = new UpCloudService($credential);

        $key = RSA::createKey(2048);
        $privateKey = $key->toString('OpenSSH');
        $publicKey = $key->getPublicKey()->toString('OpenSSH');

        $template = config('services.upcloud.default_template', '01000000-0000-4000-8000-000030200100');
        $hostname = str_replace([' ', '_'], '-', $this->server->name).'.upcloud.internal';

        $uuid = $upcloud->createServer(
            zone: $this->server->region,
            plan: $this->server->size,
            title: $this->server->name,
            hostname: $hostname,
            templateStorageUuid: $template,
            sshPublicKeys: [$publicKey]
        );

        $this->server->update([
            'provider_id' => $uuid,
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => $privateKey,
            'ssh_user' => config('services.upcloud.ssh_user', 'root'),
        ]);

        PollUpCloudIpJob::dispatch($this->server)->delay(now()->addSeconds(20));
    }
}
