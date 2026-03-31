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
        $output = $this->run(
            script: 'mkdir -p '.$this->connection->scriptPath,
            timeout: 10
        );

        if ($output->isTimeout() || $output->getExitCode() !== 0) {
            throw CouldNotCreateScriptDirectoryException::fromProcessOutput($output);
        }

        return $this;
    }

    /**
     * Returns a set of common SSH options.
     *
     * @return array<int, string> A set of SSH options.
     */
    public function sshOptions(): array
    {
        $options = [
            '-o LogLevel=ERROR', // Suppress "Permanently added … known hosts" on stderr (still logs real errors)
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
