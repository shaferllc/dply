<?php

namespace App\Jobs;

use App\Actions\Servers\ApplyFakeCloudProvisionAsReady;
use App\Models\Server;
use App\Services\LinodeService;
use App\Services\Servers\ServerProvisionSshKeyMaterial;
use App\Support\Servers\FakeCloudProvision;
use App\Support\Servers\ServerImageCatalog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProvisionLinodeServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        $credential = $this->server->providerCredential;
        if (! $credential || ! in_array($credential->provider, ['linode', 'akamai'], true)) {
            $this->markFailed('Missing or wrong-provider credential. Re-link a Linode credential to this server.');

            return;
        }

        if (FakeCloudProvision::shouldInterceptVmProvision($this->server)) {
            ApplyFakeCloudProvisionAsReady::run($this->server);

            return;
        }

        try {
            $linode = new LinodeService($credential);

            $keys = app(ServerProvisionSshKeyMaterial::class)->generate();

            $image = ServerImageCatalog::resolveForServer($this->server, 'linode')
                ?? config('services.linode.default_image', 'linode/ubuntu24.04');

            $id = $linode->createInstance(
                label: $this->server->name,
                region: $this->server->region,
                type: $this->server->size,
                image: $image,
                authorizedKeys: [$keys['recovery_public_key']]
            );
        } catch (Throwable $e) {
            $this->markFailed($this->humanizeApiError($e));

            return;
        }

        $this->server->update([
            'provider_id' => (string) $id,
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => $keys['recovery_private_key'],
            'ssh_recovery_private_key' => $keys['recovery_private_key'],
            'ssh_operational_private_key' => $keys['operational_private_key'],
            'ssh_user' => config('services.linode.ssh_user', 'root'),
        ]);

        if (isset($this->server->meta['provision_error'])) {
            $cleared = $this->server->meta;
            unset($cleared['provision_error']);
            $this->server->update(['meta' => $cleared]);
        }

        PollLinodeIpJob::dispatch($this->server)->delay(now()->addSeconds(15));
    }

    public function failed(Throwable $e): void
    {
        $this->markFailed($this->humanizeApiError($e));
    }

    private function markFailed(string $message): void
    {
        Log::warning('Linode server provision failed', [
            'server_id' => $this->server->id,
            'region' => $this->server->region,
            'size' => $this->server->size,
            'message' => $message,
        ]);

        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $meta['provision_error'] = [
            'provider' => 'linode',
            'message' => $message,
            'region' => $this->server->region,
            'size' => $this->server->size,
            'at' => now()->toIso8601String(),
        ];

        $this->server->forceFill([
            'status' => Server::STATUS_ERROR,
            'meta' => $meta,
        ])->save();
    }

    private function humanizeApiError(Throwable $e): string
    {
        $msg = trim($e->getMessage());

        if ($msg === '') {
            return 'Linode returned an unexpected error. Check the configured plan and region.';
        }

        if (stripos($msg, 'type') !== false && stripos($msg, 'region') !== false) {
            return $msg.' — pick a plan available in the selected region.';
        }

        return $msg;
    }
}
