<?php

namespace App\Jobs;

use App\Actions\Servers\ApplyFakeCloudProvisionAsReady;
use App\Models\Server;
use App\Services\DigitalOceanService;
use App\Services\Servers\ServerProvisionSshKeyMaterial;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

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

        if (FakeCloudProvision::shouldInterceptVmProvision($this->server)) {
            ApplyFakeCloudProvisionAsReady::run($this->server);

            return;
        }

        $do = new DigitalOceanService($credential);

        $keys = app(ServerProvisionSshKeyMaterial::class)->generate();

        $keyName = 'dply-'.$this->server->name.'-'.Str::random(6);
        $doKey = $do->addSshKey($keyName, $keys['recovery_public_key']);
        $sshKeyId = $doKey['id'] ?? $doKey['fingerprint'] ?? null;
        if ($sshKeyId === null) {
            $this->server->update(['status' => Server::STATUS_ERROR]);

            return;
        }

        $image = config('services.digitalocean.default_image', 'ubuntu-24-04-x64');

        $meta = $this->server->meta ?? [];
        $doOpts = is_array($meta['digitalocean'] ?? null) ? $meta['digitalocean'] : [];

        $droplet = $do->createDroplet(
            name: $this->server->name,
            region: $this->server->region,
            size: $this->server->size,
            image: $image,
            sshKeyIds: [$sshKeyId],
            options: [
                'ipv6' => (bool) ($doOpts['ipv6'] ?? false),
                'backups' => (bool) ($doOpts['backups'] ?? false),
                'monitoring' => (bool) ($doOpts['monitoring'] ?? false),
                'vpc_uuid' => isset($doOpts['vpc_uuid']) && is_string($doOpts['vpc_uuid']) && $doOpts['vpc_uuid'] !== ''
                    ? $doOpts['vpc_uuid']
                    : null,
                'tags' => isset($doOpts['tags']) && is_array($doOpts['tags']) ? $doOpts['tags'] : [],
                'user_data' => isset($doOpts['user_data']) && is_string($doOpts['user_data']) ? $doOpts['user_data'] : '',
            ],
        );

        $this->server->update([
            'provider_id' => (string) ($droplet['id'] ?? ''),
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => $keys['recovery_private_key'],
            'ssh_recovery_private_key' => $keys['recovery_private_key'],
            'ssh_operational_private_key' => $keys['operational_private_key'],
            'ssh_user' => config('services.digitalocean.ssh_user', 'root'),
        ]);

        PollDropletIpJob::dispatch($this->server)->delay(now()->addSeconds(15));
    }
}
