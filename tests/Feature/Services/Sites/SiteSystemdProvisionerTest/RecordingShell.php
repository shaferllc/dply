<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Sites\SiteSystemdProvisionerTest;

use App\Contracts\RemoteShell;

class RecordingShell implements RemoteShell
{
    /** @var list<array{path: string, contents: string}> */
    public array $putFiles = [];

    /** @var list<array{command: string, timeout: int}> */
    public array $execCalls = [];

    public function exec(string $command, int $timeoutSeconds = 120): string
    {
        $this->execCalls[] = ['command' => $command, 'timeout' => $timeoutSeconds];

        return '';
    }

    public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void
    {
        $this->putFiles[] = ['path' => $remotePath, 'contents' => $contents];
    }
}
