<?php

namespace App\Jobs;

use App\Actions\Servers\ApplyFakeCloudProvisionAsReady;
use App\Models\Server;
use App\Services\Servers\ServerProvisionSshKeyMaterial;
use App\Modules\Cloud\Services\UpCloudService;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProvisionUpCloudServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        $credential = $this->server->providerCredential;
        if (! $credential || $credential->provider !== 'upcloud') {
            $this->markFailed('Missing or wrong-provider credential. Re-link an UpCloud credential to this server.');

            return;
        }

        if (FakeCloudProvision::shouldInterceptVmProvision($this->server)) {
            ApplyFakeCloudProvisionAsReady::run($this->server);

            return;
        }

        try {
            $upcloud = new UpCloudService($credential);

            $keys = app(ServerProvisionSshKeyMaterial::class)->generate();

            $template = config('services.upcloud.default_template', '01000000-0000-4000-8000-000030200100');
            $hostname = str_replace([' ', '_'], '-', $this->server->name).'.upcloud.internal';

            $uuid = $upcloud->createServer(
                zone: $this->server->region,
                plan: $this->server->size,
                title: $this->server->name,
                hostname: $hostname,
                templateStorageUuid: $template,
                sshPublicKeys: [$keys['recovery_public_key']]
            );
        } catch (Throwable $e) {
            $this->markFailed($this->humanizeApiError($e));

            return;
        }

        $this->server->update([
            'provider_id' => $uuid,
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => $keys['recovery_private_key'],
            'ssh_recovery_private_key' => $keys['recovery_private_key'],
            'ssh_operational_private_key' => $keys['operational_private_key'],
            'ssh_user' => config('services.upcloud.ssh_user', 'root'),
        ]);

        if (isset($this->server->meta['provision_error'])) {
            $cleared = $this->server->meta;
            unset($cleared['provision_error']);
            $this->server->update(['meta' => $cleared]);
        }

        PollUpCloudIpJob::dispatch($this->server)->delay(now()->addSeconds(20));
    }

    public function failed(Throwable $e): void
    {
        $this->markFailed($this->humanizeApiError($e));
    }

    private function markFailed(string $message): void
    {
        Log::warning('UpCloud server provision failed', [
            'server_id' => $this->server->id,
            'region' => $this->server->region,
            'size' => $this->server->size,
            'message' => $message,
        ]);

        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $meta['provision_error'] = [
            'provider' => 'upcloud',
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
            return 'UpCloud returned an unexpected error. Check the configured plan and zone.';
        }

        if (stripos($msg, 'plan') !== false && stripos($msg, 'zone') !== false) {
            return $msg.' — pick a plan available in the selected zone.';
        }

        return $msg;
    }
}
