<?php

namespace App\Services;

use App\Models\Server;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

class SshConnection
{
    protected ?SSH2 $ssh = null;

    public function __construct(
        protected Server $server
    ) {}

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
        $user = $this->server->ssh_user;

        if (empty($host) || $host === '0.0.0.0') {
            return false;
        }

        $this->ssh = new SSH2($host, $port, $timeout);

        if ($this->server->ssh_private_key) {
            $key = PublicKeyLoader::load($this->server->ssh_private_key);
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
     * Upload file contents to a remote path (recursive mkdir). Uses SFTP.
     */
    public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void
    {
        $host = $this->server->ip_address;
        $port = (int) $this->server->ssh_port;
        $user = $this->server->ssh_user;

        if (empty($host) || $host === '0.0.0.0' || ! $this->server->ssh_private_key) {
            throw new \RuntimeException('SSH connection failed for server: '.$this->server->name);
        }

        $sftp = new SFTP($host, $port, $timeoutSeconds);
        $key = PublicKeyLoader::load($this->server->ssh_private_key);
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
}
