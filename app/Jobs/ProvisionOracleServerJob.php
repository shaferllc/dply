<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Servers\ApplyFakeCloudProvisionAsReady;
use App\Models\Server;
use App\Services\OracleComputeService;
use App\Services\Servers\ServerProvisionSshKeyMaterial;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProvisionOracleServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        $credential = $this->server->providerCredential;
        if (! $credential || $credential->provider !== 'oracle') {
            $this->markFailed('Missing or wrong-provider credential. Re-link an Oracle Cloud credential to this server.');

            return;
        }

        if (FakeCloudProvision::shouldInterceptVmProvision($this->server)) {
            ApplyFakeCloudProvisionAsReady::run($this->server);

            return;
        }

        try {
            $oracle = new OracleComputeService($credential);
            $keys = app(ServerProvisionSshKeyMaterial::class)->generate();

            $availabilityDomain = trim($this->server->region);
            if ($availabilityDomain === '') {
                $availabilityDomain = (string) config('services.oracle.default_availability_domain', '');
            }
            if ($availabilityDomain === '') {
                $domains = $oracle->listAvailabilityDomains();
                $availabilityDomain = (string) (($domains[0]['name'] ?? $domains[0]['id'] ?? ''));
            }
            if ($availabilityDomain === '') {
                throw new \RuntimeException('No Oracle availability domain is available for this account.');
            }

            $shape = $this->server->size !== ''
                ? $this->server->size
                : (string) config('services.oracle.default_shape', 'VM.Standard.E2.1.Micro');

            $instanceId = $oracle->launchInstance(
                displayName: $this->server->name,
                availabilityDomain: $availabilityDomain,
                shape: $shape,
                sshPublicKey: $keys['recovery_public_key'],
            );
        } catch (Throwable $e) {
            $this->markFailed($this->humanizeApiError($e));

            return;
        }

        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $meta['oracle'] = array_merge(is_array($meta['oracle'] ?? null) ? $meta['oracle'] : [], [
            'availability_domain' => $availabilityDomain,
        ]);
        unset($meta['provision_error']);

        $this->server->update([
            'provider_id' => $instanceId,
            'status' => Server::STATUS_PROVISIONING,
            'region' => $availabilityDomain,
            'size' => $shape,
            'ssh_private_key' => $keys['recovery_private_key'],
            'ssh_recovery_private_key' => $keys['recovery_private_key'],
            'ssh_operational_private_key' => $keys['operational_private_key'],
            'ssh_user' => config('services.oracle.ssh_user', 'ubuntu'),
            'meta' => $meta,
        ]);

        PollOracleIpJob::dispatch($this->server)->delay(now()->addSeconds(20));
    }

    public function failed(Throwable $e): void
    {
        $this->markFailed($this->humanizeApiError($e));
    }

    private function markFailed(string $message): void
    {
        Log::warning('Oracle Cloud server provision failed', [
            'server_id' => $this->server->id,
            'region' => $this->server->region,
            'size' => $this->server->size,
            'message' => $message,
        ]);

        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $meta['provision_error'] = [
            'provider' => 'oracle',
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
        $message = trim($e->getMessage());
        if ($message === '') {
            return 'Oracle Cloud returned an unexpected error. Check region, shape, and network configuration.';
        }

        if (stripos($message, 'subnet') !== false) {
            return $message.' — make sure the compartment has an available subnet in that AD.';
        }

        return $message;
    }
}
