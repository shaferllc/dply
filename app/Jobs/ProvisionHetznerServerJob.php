<?php

namespace App\Jobs;

use App\Actions\Servers\ApplyFakeCloudProvisionAsReady;
use App\Models\Server;
use App\Services\HetznerService;
use App\Services\Servers\ServerProvisionSshKeyMaterial;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProvisionHetznerServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        $credential = $this->server->providerCredential;
        if (! $credential || $credential->provider !== 'hetzner') {
            $this->markFailed('Missing or wrong-provider credential. Re-link a Hetzner credential to this server.');

            return;
        }

        if (FakeCloudProvision::shouldInterceptVmProvision($this->server)) {
            ApplyFakeCloudProvisionAsReady::run($this->server);

            return;
        }

        try {
            $hetzner = new HetznerService($credential);

            $keys = app(ServerProvisionSshKeyMaterial::class)->generate();

            $keyName = 'dply-'.$this->server->name.'-'.Str::random(6);
            $hetznerKey = $hetzner->addSshKey($keyName, $keys['recovery_public_key']);
            $sshKeyId = $hetznerKey['id'] ?? null;
            if ($sshKeyId === null) {
                $this->markFailed('Hetzner accepted the SSH key request but returned no id — cannot create server.');

                return;
            }

            $image = config('services.hetzner.default_image', 'ubuntu-24.04');

            $id = $hetzner->createInstance(
                name: $this->server->name,
                location: $this->server->region,
                serverType: $this->server->size,
                image: $image,
                sshKeyIds: [$sshKeyId],
                userData: ''
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
            'ssh_user' => config('services.hetzner.ssh_user', 'root'),
        ]);

        if (isset($this->server->meta['provision_error'])) {
            $cleared = $this->server->meta;
            unset($cleared['provision_error']);
            $this->server->update(['meta' => $cleared]);
        }

        PollHetznerIpJob::dispatch($this->server)->delay(now()->addSeconds(15));
    }

    public function failed(Throwable $e): void
    {
        $this->markFailed($this->humanizeApiError($e));
    }

    private function markFailed(string $message): void
    {
        Log::warning('Hetzner server provision failed', [
            'server_id' => $this->server->id,
            'region' => $this->server->region,
            'size' => $this->server->size,
            'message' => $message,
        ]);

        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $meta['provision_error'] = [
            'provider' => 'hetzner',
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
            return 'Hetzner returned an unexpected error. Check the configured server type and location.';
        }

        if (stripos($msg, 'server type') !== false && stripos($msg, 'location') !== false) {
            return $msg.' — pick a server type available in the selected location.';
        }

        return $msg;
    }
}
