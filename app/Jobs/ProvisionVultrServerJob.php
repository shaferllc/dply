<?php

namespace App\Jobs;

use App\Actions\Servers\ApplyFakeCloudProvisionAsReady;
use App\Models\Server;
use App\Services\Servers\ServerProvisionSshKeyMaterial;
use App\Services\VultrService;
use App\Support\Servers\FakeCloudProvision;
use App\Support\Servers\ServerHostingPlatformContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProvisionVultrServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        // Managed servers run on dply's OWN platform Vultr account (no customer
        // credential); BYO servers use the server's connected credential.
        $managed = $this->server->usesManagedHosting();
        $platform = null;

        if ($managed) {
            $platform = ServerHostingPlatformContext::forOrg($this->server->organization);
            if ($platform->provider !== \App\Enums\ServerProvider::Vultr || ! $platform->configured()) {
                $this->markFailed('dply-managed servers are not configured for Vultr. Set DPLY_MANAGED_PROVIDER=vultr and DPLY_MANAGED_VULTR_API_TOKEN in the environment.');

                return;
            }
        } else {
            $credential = $this->server->providerCredential;
            if (! $credential || $credential->provider !== 'vultr') {
                $this->markFailed('Missing or wrong-provider credential. Re-link a Vultr credential to this server.');

                return;
            }
        }

        if (FakeCloudProvision::shouldInterceptVmProvision($this->server)) {
            ApplyFakeCloudProvisionAsReady::run($this->server);

            return;
        }

        try {
            $vultr = $managed
                ? $platform->vultr()
                : new VultrService($this->server->providerCredential);

            $keys = app(ServerProvisionSshKeyMaterial::class)->generate();

            $sshKeyName = 'dply-'.$this->server->name.'-'.Str::random(6);
            $sshKeyId = $vultr->createSshKey($sshKeyName, $keys['recovery_public_key']);
            if ($sshKeyId === '') {
                $this->markFailed('Vultr accepted the SSH key request but returned no id — cannot create instance.');

                return;
            }

            $osId = $managed
                ? (int) ($platform->defaultImage ?: config('services.vultr.default_os_id', 2152))
                : (int) config('services.vultr.default_os_id', 2152);

            $id = $vultr->createInstance(
                region: $this->server->region,
                plan: $this->server->size,
                osId: $osId,
                label: $this->server->name,
                sshKeyIds: [$sshKeyId]
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
            'ssh_user' => config('services.vultr.ssh_user', 'root'),
        ]);

        if (isset($this->server->meta['provision_error'])) {
            $cleared = $this->server->meta;
            unset($cleared['provision_error']);
            $this->server->update(['meta' => $cleared]);
        }

        PollVultrIpJob::dispatch($this->server)->delay(now()->addSeconds(15));
    }

    public function failed(Throwable $e): void
    {
        $this->markFailed($this->humanizeApiError($e));
    }

    private function markFailed(string $message): void
    {
        Log::warning('Vultr server provision failed', [
            'server_id' => $this->server->id,
            'region' => $this->server->region,
            'size' => $this->server->size,
            'message' => $message,
        ]);

        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $meta['provision_error'] = [
            'provider' => 'vultr',
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
            return 'Vultr returned an unexpected error. Check the configured plan and region.';
        }

        if (stripos($msg, 'plan') !== false && stripos($msg, 'region') !== false) {
            return $msg.' — pick a plan available in the selected region.';
        }

        return $msg;
    }
}
