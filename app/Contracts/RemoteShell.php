<?php

namespace App\Contracts;

interface RemoteShell
{
    public function exec(string $command, int $timeoutSeconds = 120): string;

    public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void;
}
