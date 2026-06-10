<?php

declare(strict_types=1);

namespace Tests\Feature\DeployPhaseRunnerTest;

use App\Contracts\RemoteShell;

class DeployRecordingShell implements RemoteShell
{
    /** @var list<string> */
    public array $execCalls = [];

    public ?string $failOn = null;

    public function exec(string $command, int $timeoutSeconds = 120): string
    {
        $this->execCalls[] = $command;
        if ($this->failOn !== null && str_contains($command, $this->failOn)) {
            throw new \RuntimeException('Simulated step failure: '.$this->failOn);
        }

        return '';
    }

    public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void {}
}
