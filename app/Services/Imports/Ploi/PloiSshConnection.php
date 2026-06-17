<?php

declare(strict_types=1);

namespace App\Services\Imports\Ploi;

use App\Contracts\RemoteShell;
use App\Models\ImportServerMigration;
use App\Models\PloiServer;
use Illuminate\Support\Facades\Crypt;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;

/**
 * SSH client for the source Ploi server, using the ephemeral keypair pushed
 * by PushSshKeyHandler. Implements the dply RemoteShell contract so the same
 * handler code can run against either side via the same API. SSHs in as the
 * `ploi` system user (Q5).
 *
 * The instance is short-lived: one PloiSshConnection per handler invocation,
 * connected on first exec(), disconnected when GC'd or on explicit disconnect().
 */
class PloiSshConnection implements RemoteShell
{
    protected ?SSH2 $ssh = null;

    public function __construct(
        protected ImportServerMigration $migration,
        protected PloiServer $sourceServer,
    ) {}

    public static function forMigration(ImportServerMigration $migration): self
    {
        $sourceServer = PloiServer::query()
            ->where('source_id', $migration->source_server_id)
            ->whereHas('providerCredential', fn ($q) => $q->where('id', $migration->provider_credential_id))
            ->first();

        if ($sourceServer === null) {
            throw new RuntimeException('Source PloiServer missing for migration '.$migration->id);
        }
        if ($sourceServer->ip_address === null || $sourceServer->ip_address === '') {
            throw new RuntimeException('Source PloiServer has no IP address');
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

    /** @return array<string, mixed> */
    public function execWithExit(string $command, int $timeoutSeconds = 120): array
    {
        $output = $this->exec($command, $timeoutSeconds);

        return [
            'output' => $output,
            'exit_code' => $this->ssh?->getExitStatus(),
        ];
    }

    public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void
    {
        $this->connect($timeoutSeconds);
        // Use a heredoc-style write via stdin so we don't need scp/sftp here. Caller is
        // responsible for ensuring contents are safe to embed (we use base64 to bypass
        // any quoting issues).
        $b64 = base64_encode($contents);
        $cmd = "echo '{$b64}' | base64 -d > ".escapeshellarg($remotePath);
        $this->ssh->setTimeout($timeoutSeconds);
        $this->ssh->exec($cmd);
        if ($this->ssh->getExitStatus() !== 0) {
            throw new RuntimeException("Failed to write {$remotePath} on Ploi server");
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
        if (! $this->ssh->login('ploi', $key)) {
            $this->ssh = null;
            throw new RuntimeException('SSH authentication failed against Ploi server '.$this->sourceServer->ip_address);
        }
    }
}
