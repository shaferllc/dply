<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\AwsEc2Service;
use App\Services\Servers\ServerProvisionSshKeyMaterial;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionAwsEc2ServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        $credential = $this->server->providerCredential;
        if (! $credential || $credential->provider !== 'aws') {
            $this->server->update(['status' => Server::STATUS_ERROR]);

            return;
        }

        $aws = new AwsEc2Service($credential, $this->server->region);
        $keys = app(ServerProvisionSshKeyMaterial::class)->generate();

        $keyName = 'dply-'.$this->server->id.'-'.substr(uniqid(), -6);
        $keyPair = $aws->createKeyPair($keyName);
        $privateKey = $keyPair['key_material'];

        $imageId = config('services.aws.default_image', 'ami-0c55b159cbfafe1f0');
        $instanceType = $this->server->size ?: 't3.micro';

        $instanceId = $aws->runInstances(
            $imageId,
            $instanceType,
            $keyName,
            $this->server->name
        );

        $this->server->update([
            'provider_id' => $instanceId,
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => $privateKey,
            'ssh_recovery_private_key' => $privateKey,
            'ssh_operational_private_key' => $keys['operational_private_key'],
            'ssh_user' => config('services.aws.ssh_user', 'ubuntu'),
            'meta' => array_merge($this->server->meta ?? [], ['key_name' => $keyName]),
        ]);

        PollAwsEc2IpJob::dispatch($this->server)->delay(now()->addSeconds(20));
    }
}
