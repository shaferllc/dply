<?php

namespace App\Jobs;

use App\Actions\Servers\ApplyFakeCloudProvisionAsReady;
use App\Models\Server;
use App\Services\AzureComputeService;
use App\Services\Servers\ServerProvisionSshKeyMaterial;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProvisionAzureServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        $credential = $this->server->providerCredential;
        if (! $credential || $credential->provider !== 'azure') {
            $this->markFailed('Missing or wrong-provider credential. Re-link an Azure credential to this server.');

            return;
        }

        if (FakeCloudProvision::shouldInterceptVmProvision($this->server)) {
            ApplyFakeCloudProvisionAsReady::run($this->server);

            return;
        }

        try {
            $azure = new AzureComputeService($credential);
            $keys = app(ServerProvisionSshKeyMaterial::class)->generate();

            $resourceGroup = (string) (($credential->credentials ?? [])['resource_group'] ?? config('services.azure.default_resource_group', 'dply'));
            $vmName = $this->azureVmName($this->server->name, $this->server->id);
            $adminUsername = (string) config('services.azure.ssh_user', 'azureuser');

            $created = $azure->createLinuxVm(
                resourceGroup: $resourceGroup,
                location: $this->server->region,
                vmName: $vmName,
                size: $this->server->size,
                adminUsername: $adminUsername,
                sshPublicKey: $keys['recovery_public_key'],
            );
        } catch (Throwable $e) {
            $this->markFailed($e->getMessage());

            return;
        }

        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $meta['azure'] = [
            'resource_group' => $resourceGroup,
            'vm_name' => $vmName,
            'vm_id' => (string) ($created['vm_id'] ?? ''),
            'nic_id' => (string) ($created['nic_id'] ?? ''),
            'pip_id' => (string) ($created['pip_id'] ?? ''),
            'pip_name' => $this->azureResourceNameFromId((string) ($created['pip_id'] ?? '')),
        ];
        unset($meta['provision_error']);

        $this->server->update([
            'provider_id' => $vmName,
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => $keys['recovery_private_key'],
            'ssh_recovery_private_key' => $keys['recovery_private_key'],
            'ssh_operational_private_key' => $keys['operational_private_key'],
            'ssh_user' => $adminUsername,
            'meta' => $meta,
        ]);

        PollAzureIpJob::dispatch($this->server)->delay(now()->addSeconds(20));
    }

    public function failed(Throwable $e): void
    {
        $this->markFailed($e->getMessage());
    }

    private function markFailed(string $message): void
    {
        Log::warning('Azure VM provision failed', [
            'server_id' => $this->server->id,
            'region' => $this->server->region,
            'size' => $this->server->size,
            'message' => $message,
        ]);

        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $meta['provision_error'] = [
            'provider' => 'azure',
            'message' => trim($message),
            'region' => $this->server->region,
            'size' => $this->server->size,
            'at' => now()->toIso8601String(),
        ];

        $this->server->forceFill([
            'status' => Server::STATUS_ERROR,
            'meta' => $meta,
        ])->save();
    }

    private function azureVmName(string $name, string $serverId): string
    {
        $base = preg_replace('/[^a-z0-9-]/', '-', strtolower($name)) ?: 'dply-vm';
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'dply-vm';
        }
        $suffix = strtolower(substr(preg_replace('/[^a-z0-9]/i', '', $serverId) ?: 'server', -6));
        $vmName = substr($base.'-'.$suffix, 0, 63);

        return rtrim($vmName, '-');
    }

    private function azureResourceNameFromId(string $resourceId): string
    {
        $resourceId = trim($resourceId);
        if ($resourceId === '') {
            return '';
        }

        $segments = array_values(array_filter(explode('/', $resourceId)));
        if ($segments === []) {
            return '';
        }

        return urldecode((string) end($segments));
    }
}
