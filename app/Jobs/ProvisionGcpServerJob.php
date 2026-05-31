<?php

namespace App\Jobs;

use App\Actions\Servers\ApplyFakeCloudProvisionAsReady;
use App\Models\Server;
use App\Services\GcpComputeService;
use App\Services\Servers\ServerProvisionSshKeyMaterial;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProvisionGcpServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        $credential = $this->server->providerCredential;
        if (! $credential || $credential->provider !== 'gcp') {
            $this->markFailed('Missing or wrong-provider credential. Re-link a GCP credential to this server.');

            return;
        }

        if (FakeCloudProvision::shouldInterceptVmProvision($this->server)) {
            ApplyFakeCloudProvisionAsReady::run($this->server);

            return;
        }

        try {
            $gcp = new GcpComputeService($credential);
            $keys = app(ServerProvisionSshKeyMaterial::class)->generate();
            $instanceName = $this->normalizedInstanceName($this->server->name);

            $providerId = $gcp->createInstance(
                name: $instanceName,
                zone: $this->server->region,
                machineType: $this->server->size,
                sshPublicKey: $keys['recovery_public_key'],
                sshUser: (string) config('services.gcp.ssh_user', 'ubuntu'),
            );
        } catch (Throwable $e) {
            $this->markFailed($this->humanizeApiError($e));

            return;
        }

        $this->server->update([
            'provider_id' => $providerId,
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => $keys['recovery_private_key'],
            'ssh_recovery_private_key' => $keys['recovery_private_key'],
            'ssh_operational_private_key' => $keys['operational_private_key'],
            'ssh_user' => (string) config('services.gcp.ssh_user', 'ubuntu'),
        ]);

        if (isset($this->server->meta['provision_error'])) {
            $cleared = $this->server->meta;
            unset($cleared['provision_error']);
            $this->server->update(['meta' => $cleared]);
        }

        PollGcpIpJob::dispatch($this->server)->delay(now()->addSeconds(15));
    }

    public function failed(Throwable $e): void
    {
        $this->markFailed($this->humanizeApiError($e));
    }

    private function markFailed(string $message): void
    {
        Log::warning('GCP server provision failed', [
            'server_id' => $this->server->id,
            'region' => $this->server->region,
            'size' => $this->server->size,
            'message' => $message,
        ]);

        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $meta['provision_error'] = [
            'provider' => 'gcp',
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
            return 'GCP returned an unexpected error. Check service-account permissions, zone, and machine type.';
        }

        return $msg;
    }

    private function normalizedInstanceName(string $name): string
    {
        $slug = Str::of($name)
            ->lower()
            ->replaceMatches('/[^a-z0-9-]/', '-')
            ->replaceMatches('/-+/', '-')
            ->trim('-')
            ->value();

        if ($slug === '' || ! ctype_alpha($slug[0])) {
            $slug = 'dply-'.$slug;
        }

        $slug = substr($slug, 0, 54);
        $suffix = Str::lower(Str::random(8));

        return rtrim($slug, '-').'-'.$suffix;
    }
}
