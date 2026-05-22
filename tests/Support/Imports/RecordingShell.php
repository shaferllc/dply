<?php

declare(strict_types=1);

namespace Tests\Support\Imports;

use App\Services\SshConnection;

/**
 * Test double for App\Services\SshConnection. Records every exec/putFile
 * invocation and returns scripted responses in order. Lets handler tests
 * pin exact command pipelines without opening real sockets.
 *
 * Extends the concrete SshConnection (rather than just implementing
 * RemoteShell) so it can be returned from a faked SshConnectionFactory,
 * whose forServer() is typed against the concrete connection.
 */
final class RecordingShell extends SshConnection
{
    /** @var list<string> */
    public array $commands = [];

    /** @var list<string> */
    public array $responses = [];

    /** @var array<string, string> remote_path → contents written */
    public array $written = [];

    public function __construct()
    {
        // Skip SshConnection's constructor — no Server, no socket.
    }

    public function exec(string $command, int $timeoutSeconds = 120): string
    {
        $this->commands[] = $command;

        return array_shift($this->responses) ?? '';
    }

    public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void
    {
        $this->written[$remotePath] = $contents;
    }
}
