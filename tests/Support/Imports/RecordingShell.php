<?php

declare(strict_types=1);

namespace Tests\Support\Imports;

use App\Contracts\RemoteShell;

/**
 * Test double for App\Contracts\RemoteShell. Records every exec/putFile
 * invocation and returns scripted responses in order. Lets handler tests
 * pin exact command pipelines without opening real sockets.
 */
final class RecordingShell implements RemoteShell
{
    /** @var list<string> */
    public array $commands = [];

    /** @var list<string> */
    public array $responses = [];

    /** @var array<string, string> remote_path → contents written */
    public array $written = [];

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
