<?php

namespace App\Services;

use App\Contracts\RemoteShell;
use App\Models\Server;
use App\Services\Deploy\EphemeralDeployCredentialContext;
use App\Services\Servers\ServerRemoteAccessLogger;
use App\Support\Debug\SshCallRecorder;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use Throwable;

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
        $password = $this->passwordForConnection();

        if ($privateKey) {
            try {
                $key = PublicKeyLoader::load($privateKey);
            } catch (Throwable) {
                $key = null;
            }

            if ($key !== null && $this->ssh->login($user, $key)) {
                app(ServerRemoteAccessLogger::class)->touch($this->server, $user, $this->credentialRole);

                return true;
            }

            if ($password === null || ! $this->ssh->login($user, $password)) {
                $this->ssh = null;

                return false;
            }
        } elseif ($password !== null) {
            if (! $this->ssh->login($user, $password)) {
                $this->ssh = null;

                return false;
            }
        } else {
            return false;
        }

        app(ServerRemoteAccessLogger::class)->touch($this->server, $user, $this->credentialRole);

        return true;
    }

    /**
     * Run a command on the server.
     */
    public function exec(string $command, int $timeoutSeconds = 120): string
    {
        $start = microtime(true);

        if ($this->ssh === null && ! $this->connect()) {
            $this->recordDebugCall('exec', $command, $start, null, null, 'SSH connection failed');

            throw new \RuntimeException('SSH connection failed for server: '.$this->server->name);
        }

        app(ServerRemoteAccessLogger::class)->recordCommand($this->server, $command);

        $this->ssh->setTimeout($timeoutSeconds);
        $output = $this->ssh->exec($command);
        $result = $output !== false ? $output : '';

        $this->recordDebugCall('exec', $command, $start, $result, $this->lastExecExitCode());

        return $result;
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
        $start = microtime(true);

        if ($this->ssh === null && ! $this->connect()) {
            $this->recordDebugCall('stream', $command, $start, null, null, 'SSH connection failed');

            throw new \RuntimeException('SSH connection failed for server: '.$this->server->name);
        }

        app(ServerRemoteAccessLogger::class)->recordCommand($this->server, $command);

        $this->ssh->setTimeout($timeoutSeconds);
        $buffer = '';
        $result = $this->ssh->exec($command, function (string $chunk) use ($chunkCallback, &$buffer): void {
            $buffer .= $chunk;
            $chunkCallback($chunk);
        });

        if ($result === false) {
            $this->recordDebugCall('stream', $command, $start, $buffer, $this->lastExecExitCode(), 'SSH exec failed');

            throw new \RuntimeException('SSH exec failed for server: '.$this->server->name);
        }

        $this->recordDebugCall('stream', $command, $start, $buffer, $this->lastExecExitCode());

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
        $password = $this->passwordForConnection();

        if (empty($host) || $host === '0.0.0.0' || (! $privateKey && ! $password)) {
            throw new \RuntimeException('SSH connection failed for server: '.$this->server->name);
        }

        $sftp = new SFTP($host, $port, $timeoutSeconds);
        $loggedIn = false;

        if ($privateKey) {
            try {
                $key = PublicKeyLoader::load($privateKey);
                $loggedIn = $sftp->login($user, $key);
            } catch (Throwable) {
                $loggedIn = false;
            }
        }

        if (! $loggedIn && $password !== null) {
            $loggedIn = $sftp->login($user, $password);
        }

        if (! $loggedIn) {
            throw new \RuntimeException('SFTP login failed for server: '.$this->server->name);
        }

        app(ServerRemoteAccessLogger::class)->touch($this->server, $user, $this->credentialRole);
        app(ServerRemoteAccessLogger::class)->recordCommand($this->server, 'sftp:put '.$remotePath);

        $start = microtime(true);

        $dir = dirname($remotePath);
        if ($dir !== '.' && $dir !== '/') {
            $sftp->mkdir($dir, recursive: true);
        }

        if (! $sftp->put($remotePath, $contents)) {
            $this->recordDebugCall('sftp:put', $remotePath, $start, $contents, null, 'Failed to write remote file');

            throw new \RuntimeException('Failed to write remote file: '.$remotePath);
        }

        $this->recordDebugCall('sftp:put', $remotePath, $start, $contents, null);
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

    /**
     * Record an SSH call for the Debugbar SSH tab. No-op unless
     * {@see SshCallRecorder} is bound — which only happens for web requests
     * with Debugbar enabled, so this stays free in queue workers and prod.
     */
    private function recordDebugCall(
        string $type,
        string $command,
        float $start,
        ?string $output,
        ?int $exitCode,
        ?string $error = null,
    ): void {
        if (! app()->bound(SshCallRecorder::class)) {
            return;
        }

        app(SshCallRecorder::class)->record([
            'type' => $type,
            'command' => $command,
            'server_id' => $this->server->id,
            'server_name' => (string) $this->server->name,
            'host' => (string) $this->server->ip_address,
            'user' => $this->effectiveUsername(),
            'role' => $this->credentialRole,
            'exit_code' => $exitCode,
            'bytes_out' => $output !== null ? strlen($output) : null,
            'error' => $error,
            'started_at' => $start,
            'ended_at' => microtime(true),
        ]);
    }

    protected function privateKeyForConnection(): ?string
    {
        if (app()->bound(EphemeralDeployCredentialContext::class)) {
            $context = app(EphemeralDeployCredentialContext::class);
            if ($context->hasPrivateKey()) {
                return $context->privateKey();
            }
        }

        return match ($this->credentialRole) {
            self::ROLE_RECOVERY => $this->server->recoverySshPrivateKey(),
            default => $this->server->operationalSshPrivateKey(),
        };
    }

    protected function passwordForConnection(): ?string
    {
        $password = data_get($this->server->meta, 'local_runtime.ssh_password');

        return is_string($password) && trim($password) !== ''
            ? trim($password)
            : null;
    }
}
