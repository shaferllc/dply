<?php

declare(strict_types=1);

namespace Tests\Feature\ProvisionSiteSystemdUnitsJobTest;

use App\Contracts\RemoteShell;

class ProvisionRecordingShell implements RemoteShell
{
    public function exec(string $command, int $timeoutSeconds = 120): string
    {
        $this->execCalls[] = $command;

        return '';
    }

    public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void
    {
        $this->putFiles[] = ['path' => $remotePath, 'contents' => $contents];
    }
}
