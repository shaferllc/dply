<?php

declare(strict_types=1);

namespace App\Support\Debug;

/**
 * Request-scoped, in-memory log of every phpseclib SSH call made by
 * {@see \App\Services\SshConnection} during the current web request.
 *
 * Only bound in the container when Debugbar is enabled (web/dev), so the
 * accumulator never grows unbounded inside long-lived queue workers — where
 * the bulk of dply's SSH actually runs out-of-band. See
 * {@see SshCallsCollector} for how this is surfaced on the debug bar.
 *
 * @phpstan-type SshCall array{
 *     type: string,
 *     command: string,
 *     server_id: int|string|null,
 *     server_name: string,
 *     host: string,
 *     user: string,
 *     role: string,
 *     exit_code: int|null,
 *     bytes_out: int|null,
 *     error: string|null,
 *     started_at: float,
 *     ended_at: float,
 * }
 */
final class SshCallRecorder
{
    /** @var list<SshCall> */
    private array $calls = [];

    /**
     * @param  SshCall  $call
     */
    public function record(array $call): void
    {
        $this->calls[] = $call;
    }

    /**
     * @return list<SshCall>
     */
    public function all(): array
    {
        return $this->calls;
    }

    public function reset(): void
    {
        $this->calls = [];
    }
}
