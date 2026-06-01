<?php

namespace App\Jobs;

use App\Actions\Servers\ApplyFakeCloudProvisionAsReady;
use App\Models\Server;
use App\Services\ScalewayService;
use App\Services\Servers\ServerProvisionSshKeyMaterial;
use App\Support\Servers\FakeCloudProvision;
use App\Support\Servers\ServerImageCatalog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProvisionScalewayServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        $credential = $this->server->providerCredential;
        if (! $credential || $credential->provider !== 'scaleway') {
            $this->markFailed('Missing or wrong-provider credential. Re-link a Scaleway credential to this server.');

            return;
        }

        if (FakeCloudProvision::shouldInterceptVmProvision($this->server)) {
            ApplyFakeCloudProvisionAsReady::run($this->server);

            return;
        }

        try {
            $scw = new ScalewayService($credential);

            $keys = app(ServerProvisionSshKeyMaterial::class)->generate();
            $tagValue = 'AUTHORIZED_KEY='.str_replace(' ', '_', trim($keys['recovery_public_key']));

            $image = ServerImageCatalog::resolveForServer($this->server, 'scaleway')
                ?? config('services.scaleway.default_image', 'ubuntu_jammy');

            $id = $scw->createServer(
                zone: $this->server->region,
                name: $this->server->name,
                commercialType: $this->server->size,
                image: $image,
                tags: [$tagValue]
            );
        } catch (Throwable $e) {
            $this->markFailed($this->humanizeApiError($e));

            return;
        }

        $this->server->update([
            'provider_id' => $id,
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => $keys['recovery_private_key'],
            'ssh_recovery_private_key' => $keys['recovery_private_key'],
            'ssh_operational_private_key' => $keys['operational_private_key'],
            'ssh_user' => config('services.scaleway.ssh_user', 'root'),
        ]);

        if (isset($this->server->meta['provision_error'])) {
            $cleared = $this->server->meta;
            unset($cleared['provision_error']);
            $this->server->update(['meta' => $cleared]);
        }

        PollScalewayIpJob::dispatch($this->server)->delay(now()->addSeconds(20));
    }

    public function failed(Throwable $e): void
    {
        $this->markFailed($this->humanizeApiError($e));
    }

    private function markFailed(string $message): void
    {
        Log::warning('Scaleway server provision failed', [
            'server_id' => $this->server->id,
            'region' => $this->server->region,
            'size' => $this->server->size,
            'message' => $message,
        ]);

        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $meta['provision_error'] = [
            'provider' => 'scaleway',
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
            return 'Scaleway returned an unexpected error. Check the configured instance type and zone.';
        }

        if (stripos($msg, 'commercial_type') !== false || stripos($msg, 'zone') !== false) {
            return $msg.' — pick an instance type available in the selected zone.';
        }

        return $msg;
    }
}
