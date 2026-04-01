<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\UpCloudService;
use App\Services\Servers\ServerProvisionSshKeyMaterial;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

        $keys = app(ServerProvisionSshKeyMaterial::class)->generate();

        $template = config('services.upcloud.default_template', '01000000-0000-4000-8000-000030200100');
        $hostname = str_replace([' ', '_'], '-', $this->server->name).'.upcloud.internal';

        $uuid = $upcloud->createServer(
            zone: $this->server->region,
            plan: $this->server->size,
            title: $this->server->name,
            hostname: $hostname,
            templateStorageUuid: $template,
            sshPublicKeys: [$keys['recovery_public_key']]
        );

        $this->server->update([
            'provider_id' => $uuid,
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => $keys['recovery_private_key'],
            'ssh_recovery_private_key' => $keys['recovery_private_key'],
            'ssh_operational_private_key' => $keys['operational_private_key'],
            'ssh_user' => config('services.upcloud.ssh_user', 'root'),
        ]);

        PollUpCloudIpJob::dispatch($this->server)->delay(now()->addSeconds(20));
    }
}
