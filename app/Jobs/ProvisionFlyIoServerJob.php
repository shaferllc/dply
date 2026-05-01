<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\FlyIoService;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionFlyIoServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        $credential = $this->server->providerCredential;
        if (! $credential || $credential->provider !== 'fly_io') {
            $this->server->update(['status' => Server::STATUS_ERROR]);

            return;
        }

        if (FakeCloudProvision::shouldInterceptFlyIoUiStub($this->server)) {
            $appName = $this->slugAppName($this->server->name);
            $vmSize = $this->server->size ?: config('services.fly_io.default_vm_size', 'shared-cpu-1x');
            $this->server->update([
                'provider_id' => FakeCloudProvision::sentinelProviderId(),
                'region' => $this->server->region,
                'size' => $vmSize,
                'ip_address' => $appName.'.fly.dev',
                'status' => Server::STATUS_READY,
                'meta' => array_merge($this->server->meta ?? [], [
                    'app_name' => $appName,
                    'fake_cloud_provision' => true,
                    'fake_fly_io_ui_stub' => true,
                ]),
                'ssh_user' => config('services.fly_io.ssh_user', 'root'),
            ]);

            return;
        }

        $creds = $credential->credentials ?? [];
        $orgSlug = (string) ($creds['org_slug'] ?? 'personal');
        $appName = $this->slugAppName($this->server->name);

        $fly = new FlyIoService($credential);

        try {
            $fly->createApp($appName, $orgSlug);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'already exists') || str_contains($e->getMessage(), 'taken')) {
                // App name collision; append a short random suffix
                $appName = $this->slugAppName($this->server->name).'-'.bin2hex(random_bytes(3));
                $fly->createApp($appName, $orgSlug);
            } else {
                throw $e;
            }
        }

        $image = config('services.fly_io.default_image', 'registry-1.docker.io/library/ubuntu:22.04');
        $vmSize = $this->server->size ?: config('services.fly_io.default_vm_size', 'shared-cpu-1x');
        $machineId = $fly->createMachine(
            $appName,
            $this->server->region,
            $image,
            $vmSize,
            $this->slugAppName($this->server->name)
        );

        $this->server->update([
            'provider_id' => $machineId,
            'region' => $this->server->region,
            'size' => $vmSize,
            'ip_address' => $appName.'.fly.dev',
            'status' => Server::STATUS_READY,
            'meta' => array_merge($this->server->meta ?? [], ['app_name' => $appName]),
            'ssh_user' => config('services.fly_io.ssh_user', 'root'),
        ]);
    }

    private function slugAppName(string $name): string
    {
        $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($name));
        $slug = trim(preg_replace('/-+/', '-', $slug), '-');

        return $slug !== '' ? $slug : 'dply-'.bin2hex(random_bytes(4));
    }
}
