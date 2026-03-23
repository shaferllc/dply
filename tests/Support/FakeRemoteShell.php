<?php

namespace Tests\Support;

use App\Contracts\RemoteShell;

class FakeRemoteShell implements RemoteShell
{
    /** @var list<array{0: string, 1: int}> */
    public array $execCalls = [];

    /** @var list<array{0: string, 1: string}> */
    public array $putFiles = [];

    /**
     * @param  callable(string, int): string|null  $execHandler
     */
    public function __construct(
        protected $execHandler = null
    ) {}

    public function exec(string $command, int $timeoutSeconds = 120): string
    {
        $this->execCalls[] = [$command, $timeoutSeconds];
        if (is_callable($this->execHandler)) {
            $out = ($this->execHandler)($command, $timeoutSeconds);
            if ($out !== null) {
                return $out;
            }
        }

        if (preg_match('/if \[ -d .*\.git \]; then echo yes/', $command)) {
            return 'no';
        }
        if (str_contains($command, 'git clone')) {
            return "Cloning into 'repo'...\n";
        }
        if (str_contains($command, 'git rev-parse HEAD')) {
            return "deadbeef123\n";
        }
        if (str_contains($command, 'mkdir -p')) {
            return '';
        }
        if (str_contains($command, 'DPLY_HOOK_EXIT')) {
            return "\nDPLY_HOOK_EXIT:0";
        }

        return '';
    }

    public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void
    {
        $this->putFiles[] = [$remotePath, $contents];
    }
}
