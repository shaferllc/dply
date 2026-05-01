<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Concerns\AsObject;
use App\Models\Server;
use App\Services\Servers\ServerProvisionDispatch;
use App\Services\Servers\ServerProvisionSshKeyMaterial;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Support\Facades\Log;

/**
 * Dev/testing: skip cloud APIs; mark server ready against a fixed SSH target.
 */
final class ApplyFakeCloudProvisionAsReady
{
    use AsObject;

    public function handle(Server $server): void
    {
        if (! FakeCloudProvision::enabled()) {
            throw new \LogicException('Fake cloud provision is not enabled.');
        }

        $host = (string) config('server_provision_fake.ssh_host', '127.0.0.1');
        $port = (int) config('server_provision_fake.ssh_port', 22);
        $providerValue = $server->provider->value;
        $byProvider = config('server_provision_fake.ssh_user_by_provider', []);
        $userOverride = is_array($byProvider) ? ($byProvider[$providerValue] ?? null) : null;
        $user = is_string($userOverride) && trim($userOverride) !== ''
            ? trim($userOverride)
            : (string) config('server_provision_fake.ssh_user', 'root');

        $fixedKey = FakeCloudProvision::resolvedPrivateKey();
        if (is_string($fixedKey) && trim($fixedKey) !== '') {
            $recovery = trim($fixedKey);
            $operational = $recovery;
            $publicHint = null;
        } else {
            $keys = app(ServerProvisionSshKeyMaterial::class)->generate();
            $recovery = $keys['recovery_private_key'];
            $operational = $keys['operational_private_key'];
            $publicHint = $keys['recovery_public_key'];

            if (config('server_provision_fake.log_generated_public_key') && app()->environment('local')) {
                Log::info('Fake cloud provision: add this public key to the test host authorized_keys if needed.', [
                    'server_id' => $server->id,
                    'recovery_public_key' => $publicHint,
                ]);
            }
        }

        $meta = $server->meta ?? [];
        $meta['fake_cloud_provision'] = true;

        $password = config('server_provision_fake.ssh_password');
        if (is_string($password) && $password !== '') {
            $localRuntime = is_array($meta['local_runtime'] ?? null) ? $meta['local_runtime'] : [];
            $localRuntime['ssh_password'] = $password;
            $meta['local_runtime'] = $localRuntime;
        }

        $server->update([
            'provider_id' => FakeCloudProvision::sentinelProviderId(),
            'ip_address' => $host,
            'ssh_port' => $port,
            'ssh_user' => $user,
            'ssh_private_key' => $recovery,
            'ssh_recovery_private_key' => $recovery,
            'ssh_operational_private_key' => $operational,
            'status' => Server::STATUS_READY,
            'meta' => $meta,
        ]);

        $server->refresh();

        ServerProvisionDispatch::afterReady($server);
    }
}
