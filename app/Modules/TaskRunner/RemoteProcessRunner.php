<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner;

use App\Modules\TaskRunner\Exceptions\CouldNotCreateScriptDirectoryException;
use App\Modules\TaskRunner\Exceptions\CouldNotUploadFileException;
use Illuminate\Support\Facades\Process as FacadesProcess;

class RemoteProcessRunner
{
    /**
     * @var callable|null
     */
    public $onOutput = null;

    public function __construct(
        public Connection $connection,
        public ProcessRunner $processRunner
    ) {}

    /**
     * A PHP callback to run whenever there is some output available on STDOUT or STDERR.
     */
    public function onOutput(callable $callback): self
    {
        $this->onOutput = $callback;

        return $this;
    }

    /**
     * Runs the full path of given script on the remote server.
     */
    public function path(string $filename): string
    {
        return $this->connection->scriptPath.'/'.$filename;
    }

    /**
     * Creates the script directory on the remote server.
     *
     * @throws CouldNotCreateScriptDirectoryException
     */
    public function verifyScriptDirectoryExists(): self
    {
        // 30s instead of 10s: the dev container's Docker port-forward occasionally stalls
        // for 5-15s after the container restarts, and `mkdir` over SSH timing out at 10s
        // wedges the whole background-task kickoff on the local fake-cloud path.
        $output = $this->run(
            script: 'mkdir -p '.$this->connection->scriptPath,
            timeout: 30
        );

        if ($output->isTimeout() || $output->getExitCode() !== 0) {
            throw CouldNotCreateScriptDirectoryException::fromProcessOutput($output);
        }

        return $this;
    }

    /**
     * Returns a set of common SSH options.
     *
     * @return list<string>
     */
    public function sshOptions(): array
    {
        return array_merge($this->baseSshOptions(), $this->multiplexingClientOptions());
    }

    /** @return list<string> */
    private function baseSshOptions(): array
    {
        $options = [
            // ERROR silences the "Warning: Permanently added <host> ... to
            // the list of known hosts" message that ssh emits at WARNING
            // every first-time connect. With UserKnownHostsFile=/dev/null
            // it fired on every single SSH call, polluting the streaming
            // task output the operator sees in real time. Auth / connect
            // failures still surface — they're emitted at ERROR level —
            // and cleanupOutput still strips any residue from buffers
            // produced by older sshd / openssh combinations that ignore
            // LogLevel.
            '-o LogLevel=ERROR',
            '-o IdentitiesOnly=yes', // Only use the configured public key
            '-o UserKnownHostsFile=/dev/null', // Don't use known hosts
            '-o StrictHostKeyChecking=no', // Disable host key checking
            "-i {$this->connection->getPrivateKeyPath()}",
        ];

        if ($this->connection->proxyJump) {
            $options[] = "-J {$this->connection->proxyJump}";
        }

        return $options;
    }

    /**
     * Client-side multiplexing options for command ssh/scp. Uses
     * ControlMaster=no so these invocations only ATTACH to an out-of-band master
     * when one exists (skipping the TCP + auth handshake) and never *create* a
     * persistent master themselves — a command that became the master would leak
     * its stdout/stderr into the daemonised master and hang Symfony Process at
     * max_execution_time. Establishing the master is done separately in
     * {@see ensureMasterConnection}. Off unless task-runner.ssh_multiplexing.
     *
     * @return array<int, string>
     */
    private function multiplexingClientOptions(): array
    {
        if (! $this->multiplexingEnabled()) {
            return [];
        }

        $path = $this->controlPath();
        if ($path === null) {
            return [];
        }

        return [
            '-o ControlPath='.$path,
            '-o ControlMaster=no',
        ];
    }

    private function multiplexingEnabled(): bool
    {
        return (bool) config('task-runner.ssh_multiplexing', false);
    }

    /**
     * Deterministic per-(host, port, user, jump) control-socket path so that
     * separate processes (web request, queue worker) converge on the same
     * master. Self-hashed (not ssh's %C token) so the jump host is part of the
     * key and the path stays well under the ~104-char unix-socket limit. Null
     * if the private socket directory can't be prepared, in which case
     * multiplexing is silently skipped.
     */
    private function controlPath(): ?string
    {
        $uid = function_exists('posix_getuid') ? posix_getuid() : @getmyuid();
        $dir = '/tmp/dply-ssh-'.(is_int($uid) ? $uid : 'shared');

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            return null;
        }
        @chmod($dir, 0700);

        $token = substr(sha1(implode('|', [
            $this->connection->host,
            $this->connection->port,
            $this->connection->username,
            $this->connection->proxyJump ?? '',
        ])), 0, 16);

        return $dir.'/'.$token;
    }

    /**
     * Best-effort: make sure a persistent control master exists for this server
     * so the following command can attach to it. Fast-checks the socket first
     * and only opens a master when none is live. The master is launched
     * detached (-f -N) with its stdio redirected to /dev/null via the shell, so
     * — unlike a command that becomes the master — nothing it inherits can block
     * the PHP Process. Any failure here is swallowed: command ssh uses
     * ControlMaster=no and simply connects directly when there's no master.
     */
    private function ensureMasterConnection(): void
    {
        if (! $this->multiplexingEnabled()) {
            return;
        }
        $path = $this->controlPath();
        if ($path === null) {
            return;
        }

        $target = "{$this->connection->username}@{$this->connection->host}";

        try {
            // Quick local check — exits 0 when a master is already running.
            $check = FacadesProcess::timeout(5)->run(implode(' ', [
                'ssh', '-O', 'check',
                '-o', 'ControlPath='.$path,
                '-p', (string) $this->connection->port,
                $target,
            ]).' >/dev/null 2>&1');

            if ($check->successful()) {
                return;
            }

            // No live master — open one in the background. The shell redirect is
            // what makes this safe: the daemonised master has no inherited pipe
            // for Process to wait on.
            $persist = (string) config('task-runner.ssh_control_persist', '60s');
            FacadesProcess::timeout(20)->run(implode(' ', array_merge(
                ['ssh', '-f', '-N'],
                $this->baseSshOptions(),
                [
                    '-o ControlMaster=auto',
                    '-o ControlPath='.$path,
                    '-o ControlPersist='.$persist,
                    "-p {$this->connection->port}",
                    $target,
                ],
            )).' >/dev/null 2>&1');
        } catch (\Throwable) {
            // Multiplexing is an optimisation; never let it break the real call.
        }
    }

    /**
     * Formats the script and output paths, and runs the script.
     */
    public function runUploadedScript(string $script, string $output, int $timeout = 0): ProcessOutput
    {
        $scriptPath = $this->path($script);
        $outputPath = $this->path($output);

        return $this->run("bash {$scriptPath} 2>&1 | tee {$outputPath}", $timeout);
    }

    /**
     * Formats the script and output paths, and runs the script in the background.
     */
    public function runUploadedScriptInBackground(string $script, string $output, int $timeout = 0): ProcessOutput
    {
        $scriptPath = $this->path($script);
        $outputPath = $this->path($output);

        $script = $timeout > 0
            ? "timeout {$timeout}s bash {$scriptPath} > {$outputPath} 2>&1 &"
            : "bash {$scriptPath} > {$outputPath} 2>&1 &";

        return $this->run($script, 10);
    }

    /**
     * Wraps the script in a bash subshell command, and runs it over SSH.
     */
    public function run(string $script, int $timeout = 0): ProcessOutput
    {
        $this->ensureMasterConnection();

        $command = implode(' ', [
            'ssh',
            ...$this->sshOptions(),
            "-p {$this->connection->port}",
            "{$this->connection->username}@{$this->connection->host}",
            Helper::scriptInSubshell($script),
        ]);

        $output = $this->processRunner->run(
            FacadesProcess::command($command)->timeout($timeout > 0 ? $timeout : null),
            $this->onOutput
        );

        return $this->cleanupOutput($output);
    }

    /**
     * Removes the known hosts warning from the output.
     */
    public function cleanupOutput(ProcessOutput $processOutput): ProcessOutput
    {
        $buffer = $processOutput->getBuffer();
        // Strip known-hosts chatter if it still appears (e.g. older OpenSSH); may span multiple lines.
        $buffer = (string) preg_replace('/^Warning: Permanently added[^\n]*\R?/m', '', $buffer);

        return ProcessOutput::make(trim($buffer))
            ->setExitCode($processOutput->getExitCode())
            ->setTimeout($processOutput->isTimeout());
    }

    /**
     * Uploads the given contents to the script directory with the given filename.
     *
     * @param  string  $filename
     * @param  string  $contents
     */
    public function upload($filename, $contents): self
    {
        $localPath = Helper::temporaryDirectoryPath($filename);
        file_put_contents($localPath, $contents);

        $this->ensureMasterConnection();

        $command = implode(' ', [
            'scp',
            ...$this->sshOptions(),
            '-P '.$this->connection->port,
            $localPath,
            "{$this->connection->username}@{$this->connection->host}:".$this->path($filename),
        ]);

        $output = $this->processRunner->run(
            FacadesProcess::command($command)->timeout(10)
        );

        if ($output->isTimeout() || $output->getExitCode() !== 0) {
            throw CouldNotUploadFileException::fromProcessOutput($output);
        }

        return $this;
    }
}
