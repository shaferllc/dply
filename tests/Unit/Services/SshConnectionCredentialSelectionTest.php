<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Services\SshConnection;
use Tests\TestCase;

class SshConnectionCredentialSelectionTest extends TestCase
{
    private function validPrivateKey(): string
    {
        $path = base_path('app/TaskRunner/Tests/fixtures/private_key.pem');

        return file_get_contents($path);
    }

    public function test_operational_role_prefers_operational_private_key(): void
    {
        $connection = new class(new Server([
            'ssh_user' => 'deploy',
            'ssh_private_key' => 'legacy-key',
            'ssh_operational_private_key' => $this->validPrivateKey(),
            'ssh_recovery_private_key' => null,
        ]), 'deploy', 'operational') extends SshConnection
        {
            public function exposedPrivateKey(): ?string
            {
                return $this->privateKeyForConnection();
            }
        };

        $this->assertSame($this->validPrivateKey(), $connection->exposedPrivateKey());
    }

    public function test_recovery_role_prefers_recovery_private_key(): void
    {
        $connection = new class(new Server([
            'ssh_user' => 'deploy',
            'ssh_private_key' => 'legacy-key',
            'ssh_operational_private_key' => null,
            'ssh_recovery_private_key' => $this->validPrivateKey(),
        ]), 'root', 'recovery') extends SshConnection
        {
            public function exposedPrivateKey(): ?string
            {
                return $this->privateKeyForConnection();
            }
        };

        $this->assertSame($this->validPrivateKey(), $connection->exposedPrivateKey());
    }

    public function test_recovery_role_falls_back_to_legacy_private_key_during_rollout(): void
    {
        $connection = new class(new Server([
            'ssh_user' => 'deploy',
            'ssh_private_key' => $this->validPrivateKey(),
            'ssh_operational_private_key' => null,
            'ssh_recovery_private_key' => null,
        ]), 'root', 'recovery') extends SshConnection
        {
            public function exposedPrivateKey(): ?string
            {
                return $this->privateKeyForConnection();
            }
        };

        $this->assertSame($this->validPrivateKey(), $connection->exposedPrivateKey());
    }

    public function test_local_runtime_password_is_available_for_orbstack_fallback(): void
    {
        $connection = new class(new Server([
            'ssh_user' => 'dplytest',
            'ssh_private_key' => 'not-a-real-key',
            'meta' => [
                'local_runtime' => [
                    'provider' => 'orbstack',
                    'ssh_password' => 'dplylocal',
                ],
            ],
        ]), 'dplytest', 'operational') extends SshConnection
        {
            public function exposedPassword(): ?string
            {
                return $this->passwordForConnection();
            }
        };

        $this->assertSame('dplylocal', $connection->exposedPassword());
    }
}
