<?php

declare(strict_types=1);

namespace App\Services\Imports\Forge;

use App\Contracts\RemoteShell;
use App\Models\ForgeServer;
use App\Models\ImportServerMigration;
use Illuminate\Support\Facades\Crypt;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;

/**
 * SSH client for the source Forge server, using the ephemeral keypair pushed
 * by PushSshKeyHandler. Mirrors PloiSshConnection but SSHs in as the `forge`
 * system user (Forge's convention; the equivalent of Ploi's `ploi` user).
 *
 * Lifecycle: one ForgeSshConnection per handler invocation, connected on first
 * exec(), torn down on destruct.
 */
class ForgeSshConnection implements RemoteShell
{
    protected ?SSH2 $ssh = null;

    public function __construct(
        protected ImportServerMigration $migration,
        protected ForgeServer $sourceServer,
    ) {}

    public static function forMigration(ImportServerMigration $migration): self
    {
        $sourceServer = ForgeServer::query()
            ->where('source_id', $migration->source_server_id)
            ->whereHas('providerCredential', fn ($q) => $q->where('id', $migration->provider_credential_id))
            ->first();

        if ($sourceServer === null) {
            throw new RuntimeException('Source ForgeServer missing for migration '.$migration->id);
        }
        if ($sourceServer->ip_address === null || $sourceServer->ip_address === '') {
            throw new RuntimeException('Source ForgeServer has no IP address');
        }
        if ($migration->ssh_key_private_encrypted === null) {
            throw new RuntimeException('Ephemeral SSH key missing — run push_ssh_key first');
        }

        return new self($migration, $sourceServer);
    }

    public function exec(string $command, int $timeoutSeconds = 120): string
    {
        $this->connect($timeoutSeconds);
        $this->ssh->setTimeout($timeoutSeconds);

        return (string) $this->ssh->exec($command);
    }

    public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void
    {
        $this->connect($timeoutSeconds);
        $b64 = base64_encode($contents);
        $cmd = "echo '{$b64}' | base64 -d > ".escapeshellarg($remotePath);
        $this->ssh->setTimeout($timeoutSeconds);
        $this->ssh->exec($cmd);
        if ($this->ssh->getExitStatus() !== 0) {
            throw new RuntimeException("Failed to write {$remotePath} on Forge server");
        }
    }

    public function disconnect(): void
    {
        if ($this->ssh !== null) {
            try {
                $this->ssh->disconnect();
            } catch (\Throwable) {
                // best-effort
            }
            $this->ssh = null;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    protected function connect(int $timeout): void
    {
        if ($this->ssh !== null) {
            return;
        }

        $privateKey = Crypt::decryptString($this->migration->ssh_key_private_encrypted);
        $key = PublicKeyLoader::load($privateKey);

        $this->ssh = new SSH2($this->sourceServer->ip_address, 22, $timeout);
        if (! $this->ssh->login('forge', $key)) {
            $this->ssh = null;
            throw new RuntimeException('SSH authentication failed against Forge server '.$this->sourceServer->ip_address);
        }
    }
}
