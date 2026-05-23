<?php

namespace Tests\Support;

use App\Services\SshConnection;

/**
 * Extends the concrete SshConnection (rather than just implementing
 * RemoteShell) so it satisfies SshConnectionFactory::forServer(), which is
 * typed against the concrete connection.
 */
class FakeRemoteShell extends SshConnection
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

    public function connect(int $timeout = 10): bool
    {
        return true;
    }

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

    /**
     * @param  callable(string):void  $chunkCallback
     * @return array{0: string, 1: ?int}
     */
    public function execWithCallbackAndExit(string $command, callable $chunkCallback, int $timeoutSeconds = 120): array
    {
        $out = $this->exec($command, $timeoutSeconds);
        if ($out !== '') {
            $chunkCallback($out);
        }

        return [$out, 0];
    }

    public function putFile(string $remotePath, string $contents, int $timeoutSeconds = 60): void
    {
        $this->putFiles[] = [$remotePath, $contents];
    }
}
