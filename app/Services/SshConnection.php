<?php

namespace App\Services;

use App\Contracts\RemoteShell;
use App\Models\Server;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

class SshConnection implements RemoteShell
{
    public const ROLE_OPERATIONAL = 'operational';

    public const ROLE_RECOVERY = 'recovery';

    protected ?SSH2 $ssh = null;

    public function __construct(
        protected Server $server,
        protected ?string $loginUsername = null,
        protected string $credentialRole = self::ROLE_OPERATIONAL,
    ) {}

    /**
     * SSH username for this connection (override or server default).
     */
    public function effectiveUsername(): string
    {
        if ($this->loginUsername !== null && trim($this->loginUsername) !== '') {
            return trim($this->loginUsername);
        }

        $u = trim((string) $this->server->ssh_user);

        return $u !== '' ? $u : 'root';
    }

    /**
     * Connect to the server via SSH (key or password).
     */
    public function connect(int $timeout = 10): bool
    {
        if ($this->ssh !== null) {
            return true;
        }

        $host = $this->server->ip_address;
        $port = (int) $this->server->ssh_port;
        $user = $this->effectiveUsername();

        if (empty($host) || $host === '0.0.0.0') {
            return false;
        }

        $this->ssh = new SSH2($host, $port, $timeout);

        $privateKey = $this->privateKeyForConnection();

        if ($privateKey) {
            $key = PublicKeyLoader::load($privateKey);
            if (! $this->ssh->login($user, $key)) {
                $this->ssh = null;

                return false;
            }
        } else {
            return false;
        }

        return true;
    }

    /**
     * Run a command on the server.
     */
    public function exec(string $command, int $timeoutSeconds = 120): string
    {
        if ($this->ssh === null && ! $this->connect()) {
            throw new \RuntimeException('SSH connection failed for server: '.$this->server->name);
        }

        $this->ssh->setTimeout($timeoutSeconds);
        $output = $this->ssh->exec($command);

        return $output !== false ? $output : '';
    }

    /**
     * Exit status of the last {@see exec()} or {@see execWithCallback()} (SSH channel close).
     */
    public function lastExecExitCode(): ?int
    {
        if ($this->ssh === null) {
            return null;
        }

        $s = $this->ssh->getExitStatus();

        return $s === false ? null : $s;
    }

    /**
     * Run a command and invoke the callback for each output chunk (phpseclib channel packets).
     *
     * @param  callable(string):void  $chunkCallback
     */
    public function execWithCallback(string $command, callable $chunkCallback, int $timeoutSeconds = 120): string
    {
        if ($this->ssh === null && ! $this->connect()) {
            throw new \RuntimeException('SSH connection failed for server: '.$this->server->name);
        }

        $this->ssh->setTimeout($timeoutSeconds);
        $buffer = '';
        $result = $this->ssh->exec($command, function (string $chunk) use ($chunkCallback, &$buffer): void {
            $buffer .= $chunk;
            $chunkCallback($chunk);
        });

        if ($result === false) {
            throw new \RuntimeException('SSH exec failed for server: '.$this->server->name);
        }

        return $buffer;
    }

    /**
     * Same as {@see execWithCallback} but returns output and exit code together.
     *
     * @param  callable(string):void  $chunkCallback
     * @return array{0: string, 1: ?int}
     */
    public function execWithCallbackAndExit(string $command, callable $chunkCallback, int $timeoutSeconds = 120): array
    {
        $out = $this->execWithCallback($command, $chunkCallback, $timeoutSeconds);
        $exit = $this->lastExecExitCode();

        return [$out, $exit];
    }

    /**
     * Upload file contents to a remote path (recursive mkdir). Uses SFTP.
     */
    public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void
    {
        $host = $this->server->ip_address;
        $port = (int) $this->server->ssh_port;
        $user = $this->effectiveUsername();
        $privateKey = $this->privateKeyForConnection();

        if (empty($host) || $host === '0.0.0.0' || ! $privateKey) {
            throw new \RuntimeException('SSH connection failed for server: '.$this->server->name);
        }

        $sftp = new SFTP($host, $port, $timeoutSeconds);
        $key = PublicKeyLoader::load($privateKey);
        if (! $sftp->login($user, $key)) {
            throw new \RuntimeException('SFTP login failed for server: '.$this->server->name);
        }

        $dir = dirname($remotePath);
        if ($dir !== '.' && $dir !== '/') {
            $sftp->mkdir($dir, recursive: true);
        }

        if (! $sftp->put($remotePath, $contents)) {
            throw new \RuntimeException('Failed to write remote file: '.$remotePath);
        }
    }

    /**
     * Run multiple commands (each in its own channel; combine with && if you need state).
     */
    public function execMany(array $commands): array
    {
        $results = [];
        foreach ($commands as $cmd) {
            $results[] = $this->exec($cmd);
        }

        return $results;
    }

    /**
     * Disconnect.
     */
    public function disconnect(): void
    {
        $this->ssh = null;
    }

    public function isConnected(): bool
    {
        return $this->ssh !== null;
    }

    protected function privateKeyForConnection(): ?string
    {
        return match ($this->credentialRole) {
            self::ROLE_RECOVERY => $this->server->recoverySshPrivateKey(),
            default => $this->server->operationalSshPrivateKey(),
        };
    }
}
