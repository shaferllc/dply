<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\ServerCacheService;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;

/**
 * Runs a single redis-cli / valkey-cli / keydb-cli command against an engine on a
 * server over SSH and returns the raw response. Used by the workspace REPL and by
 * features (key browser, etc.) that issue ad-hoc commands.
 *
 * Memcached has no equivalent CLI surface here; callers must check
 * `ServerCacheService::engineSupportsAuth()` and route memcached interactions
 * elsewhere.
 */
class CacheServiceCli
{
    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $executor,
    ) {}

    /**
     * Execute a single cli command and return the process output. The command is
     * tokenised on whitespace and each token is escapeshellarg'd individually before
     * being assembled — the cli itself handles redis quoting/escaping at the wire
     * layer, so we only need to keep shell metacharacters out.
     *
     * Throws RuntimeException for SSH/process-level failures (missing binary, no
     * route to host, etc.). A non-zero exit code from the cli (e.g. WRONGTYPE) is
     * NOT thrown — the caller is expected to render the cli's stderr/stdout in the
     * REPL output region the same way you would in a terminal.
     */
    public function execute(
        Server $server,
        ServerCacheService $row,
        string $command,
        int $timeoutSeconds = 10,
    ): ProcessOutput {
        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            throw new \InvalidArgumentException("CacheServiceCli does not support {$row->engine}.");
        }

        $tokens = $this->tokenize($command);
        if ($tokens === []) {
            throw new \InvalidArgumentException('Empty command.');
        }

        $cli = CacheServiceStats::binaryFor($row->engine);
        // AUTH flag must come AFTER the cli binary, not before — `-a 'pw' valkey-cli …`
        // makes bash run `-a` as the program name (fails with "-a: command not found"
        // and visibly leaks the password into the visible REPL error trail).
        // `--no-auth-warning` suppresses the stderr "using -a on the command line may
        // not be safe" line that would otherwise pollute REPL output.
        $authFlag = filled($row->auth_password ?? null)
            ? ' -a '.escapeshellarg((string) $row->auth_password).' --no-auth-warning'
            : '';

        // --no-raw keeps responses in the human-readable form (`(integer) 42`,
        // `(nil)`, `1) "foo"`) which is what an operator expects in a REPL.
        $cliBin = escapeshellarg($cli);
        $argString = implode(' ', array_map(static fn (string $t): string => escapeshellarg($t), $tokens));
        $port = (int) $row->port;

        // Branch with `if … elif redis-cli … else error`. Avoids the prior `cli || redis-cli`
        // shape where redis-cli would fire on ANY non-zero from the engine's own cli (including
        // routine cli errors like WRONGTYPE), and where a missing redis-cli surfaced as a
        // confusing "command not found" trail in the REPL output.
        // Also: do NOT pass `-t` — modern valkey-cli rejects it as an unknown option, and the
        // outer SSH timeout already bounds the round-trip.
        $line = <<<BASH
if command -v {$cliBin} >/dev/null 2>&1; then
    {$cliBin}{$authFlag} -p {$port} --no-raw {$argString}
elif command -v redis-cli >/dev/null 2>&1; then
    redis-cli{$authFlag} -p {$port} --no-raw {$argString}
else
    echo "ERROR: No RESP client found on this server (tried {$cli}, redis-cli)." >&2
    exit 127
fi
BASH;

        return $this->executor->runInlineBash(
            $server,
            'cache-service:cli:'.$row->engine,
            $line,
            timeoutSeconds: max(5, $timeoutSeconds + 5),
            asRoot: false,
        );
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $command): array
    {
        $trimmed = trim($command);
        if ($trimmed === '') {
            return [];
        }

        return preg_split('/\s+/', $trimmed) ?: [];
    }
}
