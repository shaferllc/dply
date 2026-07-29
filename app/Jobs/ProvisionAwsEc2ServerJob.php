<?php

namespace App\Jobs;

use App\Actions\Servers\ApplyFakeCloudProvisionAsReady;
use App\Models\Server;
use App\Modules\Cloud\Services\AwsEc2ServiceFactory;
use App\Services\Servers\ServerProvisionSshKeyMaterial;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProvisionAwsEc2ServerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public Server $server
    ) {}

    public function handle(): void
    {
        $credential = $this->server->providerCredential;
        if (! $credential || $credential->provider !== 'aws') {
            $this->markFailed('Missing or wrong-provider credential. Re-link an AWS credential to this server.');

            return;
        }

        if (FakeCloudProvision::shouldInterceptVmProvision($this->server)) {
            ApplyFakeCloudProvisionAsReady::run($this->server);

            return;
        }

        $aws = app(AwsEc2ServiceFactory::class)->make($credential, $this->server->region);
        $keyName = null;

        try {
            $keys = app(ServerProvisionSshKeyMaterial::class)->generate();

            $keyName = 'dply-'.$this->server->id.'-'.substr(uniqid(), -6);
            $keyPair = $aws->createKeyPair($keyName);
            $privateKey = $keyPair['key_material'];

            $imageId = $aws->resolveDefaultImageId();
            $securityGroupId = $aws->resolveProvisionSecurityGroupId();
            $instanceType = $this->server->size ?: 't3.micro';

            $instanceId = $aws->runInstances(
                $imageId,
                $instanceType,
                $keyName,
                $this->server->name,
                $securityGroupId,
            );
        } catch (Throwable $e) {
            if ($keyName !== null) {
                try {
                    $aws->deleteKeyPair($keyName);
                } catch (Throwable) {
                    //
                }
            }

            $this->markFailed($this->humanizeApiError($e));

            return;
        }

        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $meta['key_name'] = $keyName;
        unset($meta['provision_error']);

        $this->server->update([
            'provider_id' => $instanceId,
            'status' => Server::STATUS_PROVISIONING,
            'ssh_private_key' => $privateKey,
            'ssh_recovery_private_key' => $privateKey,
            'ssh_operational_private_key' => $keys['operational_private_key'],
            'ssh_user' => config('services.aws.ssh_user', 'ubuntu'),
            'meta' => $meta,
        ]);

        PollAwsEc2IpJob::dispatch($this->server)->delay(now()->addSeconds(20));
    }

    public function failed(Throwable $e): void
    {
        $this->markFailed($this->humanizeApiError($e));
    }

    private function markFailed(string $message): void
    {
        Log::warning('AWS EC2 server provision failed', [
            'server_id' => $this->server->id,
            'region' => $this->server->region,
            'size' => $this->server->size,
            'message' => $message,
        ]);

        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $meta['provision_error'] = [
            'provider' => 'aws',
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
            return 'AWS EC2 returned an unexpected error. Check IAM permissions, region, and instance type.';
        }

        if (stripos($msg, 'UnauthorizedOperation') !== false || stripos($msg, 'AccessDenied') !== false) {
            return $msg.' — the IAM user needs EC2 and SSM permissions for provisioning.';
        }

        if (stripos($msg, 'InvalidAMIID') !== false || stripos($msg, 'InvalidAMIID.NotFound') !== false) {
            return $msg.' — set AWS_EC2_DEFAULT_IMAGE to a valid AMI in the selected region.';
        }

        return $msg;
    }
}
