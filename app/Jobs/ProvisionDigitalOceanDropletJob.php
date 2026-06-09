<?php

namespace App\Jobs;

use App\Actions\Servers\ApplyFakeCloudProvisionAsReady;
use App\Models\Server;
use App\Services\DigitalOceanService;
use App\Services\Servers\ServerProvisionSshKeyMaterial;
use App\Support\Servers\FakeCloudProvision;
use App\Support\Servers\ServerImageCatalog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProvisionDigitalOceanDropletJob implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt. DigitalOcean errors like "Size is not available in this
     * region" or "validation_failed" are configuration mistakes, not
     * transient — retrying just produces the same error twice. If we ever
     * want retries for genuinely transient 5xx errors, gate them in handle()
     * on the response status.
     */
    public int $tries = 1;

    public function __construct(
        public Server $server
    ) {
        $this->onQueue(config('server_provision.queue', 'dply'));
    }

    public function handle(): void
    {
        $credential = $this->server->providerCredential;
        if (! $credential || $credential->provider !== 'digitalocean') {
            $this->markFailed('Missing or wrong-provider credential. Re-link a DigitalOcean credential to this server.');

            return;
        }

        if (FakeCloudProvision::shouldInterceptVmProvision($this->server)) {
            ApplyFakeCloudProvisionAsReady::run($this->server);

            return;
        }

        try {
            $do = new DigitalOceanService($credential);

            $keys = app(ServerProvisionSshKeyMaterial::class)->generate();

            $keyName = 'dply-'.$this->server->name.'-'.Str::random(6);
            $doKey = $do->addSshKey($keyName, $keys['recovery_public_key']);
            $sshKeyId = $doKey['id'] ?? $doKey['fingerprint'] ?? null;
            if ($sshKeyId === null) {
                $this->markFailed('DigitalOcean accepted the SSH key request but returned neither id nor fingerprint — cannot create droplet.');

                return;
            }

            // Image precedence: an explicit user-chosen OS image wins; otherwise
            // launch from a region-matched pre-baked snapshot (fast path — stack
            // already installed, setup script skip-fasts); otherwise stock Ubuntu.
            $image = ServerImageCatalog::resolveForServer($this->server, 'digitalocean')
                ?? ServerImageCatalog::bakedSnapshotForRegion('digitalocean', $this->server->region)
                ?? config('services.digitalocean.default_image', 'ubuntu-24-04-x64');

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
                    // Prefer a user-supplied user_data; otherwise inject the boot
                    // head-start (apt warmup at boot) when enabled. No-op when off.
                    'user_data' => (isset($doOpts['user_data']) && is_string($doOpts['user_data']) && $doOpts['user_data'] !== '')
                        ? $doOpts['user_data']
                        : (\App\Support\Servers\BootHeadStartScript::enabled()
                            ? \App\Support\Servers\BootHeadStartScript::cloudInitUserData()
                            : ''),
                ],
            );
        } catch (Throwable $e) {
            $this->markFailed($this->humanizeApiError($e));

            return;
        }

        $this->server->update([
            'provider_id' => (string) ($droplet['id'] ?? ''),
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => $keys['recovery_private_key'],
            'ssh_recovery_private_key' => $keys['recovery_private_key'],
            'ssh_operational_private_key' => $keys['operational_private_key'],
            'ssh_user' => config('services.digitalocean.ssh_user', 'root'),
        ]);

        // Clear any previous provision error on success — same row, new attempt.
        if (isset($this->server->meta['provision_error'])) {
            $cleared = $this->server->meta;
            unset($cleared['provision_error']);
            $this->server->update(['meta' => $cleared]);
        }

        // DigitalOcean assigns a public IP within a few seconds of create;
        // a short delay before the first poll avoids a guaranteed-empty call
        // without adding meaningful wall-clock (the 5s backoff handles the rest).
        PollDropletIpJob::dispatch($this->server)->delay(now()->addSeconds(5));
    }

    public function failed(Throwable $e): void
    {
        // Belt and braces — if anything escaped handle(), still surface the
        // error to the user instead of leaving the server in pending.
        $this->markFailed($this->humanizeApiError($e));
    }

    private function markFailed(string $message): void
    {
        Log::warning('DigitalOcean droplet provision failed', [
            'server_id' => $this->server->id,
            'region' => $this->server->region,
            'size' => $this->server->size,
            'message' => $message,
        ]);

        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $meta['provision_error'] = [
            'provider' => 'digitalocean',
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
            return 'DigitalOcean returned an unexpected error. Check the configured size and region.';
        }

        // Add a hint for the most common configuration mistake — region/size mismatch.
        if (stripos($msg, 'size is not available') !== false) {
            return $msg.' — pick a different droplet size or a region that supports this size (see the size matrix at https://docs.digitalocean.com/products/droplets/details/availability-matrix/).';
        }

        return $msg;
    }
}
