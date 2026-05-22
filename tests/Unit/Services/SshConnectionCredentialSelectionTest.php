<?php


namespace Tests\Unit\Services\SshConnectionCredentialSelectionTest;
use App\Models\Server;
use \App\Services\SshConnection;

function validPrivateKey(): string
{
    $path = base_path('app/TaskRunner/Tests/fixtures/private_key.pem');

    return file_get_contents($path);
}

test('operational role prefers operational private key', function () {
    $connection = new class(new Server(['ssh_user' => 'deploy', 'ssh_private_key' => 'legacy-key', 'ssh_operational_private_key' => validPrivateKey(), 'ssh_recovery_private_key' => null]), 'deploy', 'operational') extends SshConnection
    {
        function exposedPrivateKey(): ?string
        {
            return $this->privateKeyForConnection();
        }
    };

    expect($connection->exposedPrivateKey())->toBe(validPrivateKey());
});

test('recovery role prefers recovery private key', function () {
    $connection = new class(new Server(['ssh_user' => 'deploy', 'ssh_private_key' => 'legacy-key', 'ssh_operational_private_key' => null, 'ssh_recovery_private_key' => validPrivateKey()]), 'root', 'recovery') extends SshConnection
    {
        function exposedPrivateKey(): ?string
        {
            return $this->privateKeyForConnection();
        }
    };

    expect($connection->exposedPrivateKey())->toBe(validPrivateKey());
});

test('recovery role falls back to legacy private key during rollout', function () {
    $connection = new class(new Server(['ssh_user' => 'deploy', 'ssh_private_key' => validPrivateKey(), 'ssh_operational_private_key' => null, 'ssh_recovery_private_key' => null]), 'root', 'recovery') extends SshConnection
    {
        function exposedPrivateKey(): ?string
        {
            return $this->privateKeyForConnection();
        }
    };

    expect($connection->exposedPrivateKey())->toBe(validPrivateKey());
});

test('local runtime password is available for orbstack fallback', function () {
    $connection = new class(new Server(['ssh_user' => 'dplytest', 'ssh_private_key' => 'not-a-real-key', 'meta' => ['local_runtime' => ['provider' => 'orbstack', 'ssh_password' => 'dplylocal']]]), 'dplytest', 'operational') extends SshConnection
    {
        function exposedPassword(): ?string
        {
            return $this->passwordForConnection();
        }
    };

    expect($connection->exposedPassword())->toBe('dplylocal');
});
