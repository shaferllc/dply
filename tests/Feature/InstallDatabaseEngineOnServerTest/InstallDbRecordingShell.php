<?php

declare(strict_types=1);

namespace Tests\Feature\InstallDatabaseEngineOnServerTest;

use App\Contracts\RemoteShell;

class InstallDbRecordingShell implements RemoteShell
{
    /** @var list<string> */
    public array $execCalls = [];

    public function exec(string $command, int $timeoutSeconds = 120): string
    {
        $this->execCalls[] = $command;

        return '';
    }

    public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void {}
}
