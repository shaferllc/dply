<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\Servers\ServerCacheMonitorChunkBroadcast;
use App\Events\Servers\ServerCacheMonitorCompletedBroadcast;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerCacheServiceAuditEvent;
use App\Services\Servers\CacheServiceAuditLogger;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\CacheServiceStats;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Runs `redis-cli MONITOR` against an instance for a bounded window
 * (≤30 s) and streams the output to the workspace via two parallel
 * channels:
 *
 *   1. Reverb broadcasts on the existing `server.{serverId}` private
 *      channel — for live-feel UX in environments with Reverb up.
 *   2. A bounded line buffer in the application cache, keyed by the
 *      run id — the workspace's `wire:poll.1s` reads this, so MONITOR
 *      works even when Reverb isn't configured.
 *
 * MONITOR is technically read-only (no key mutations) but it forces
 * Redis to copy every command across all connections to this client,
 * which can chunk through a meaningful slice of the engine's CPU on a
 * hot cache. The workspace gates the start button behind the existing
 * REPL unlock toggle to make that visibility cost an explicit choice.
 */
class TailCacheServiceMonitorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    public const HARD_MAX_DURATION = 30;

    public const MIN_DURATION = 5;

    /** Soft cap on lines kept in the cache buffer; the UI shows the tail. */
    public const MAX_BUFFER_LINES = 500;

    public function __construct(
        public string $serverId,
        public string $cacheServiceId,
        public string $runId,
        public int $durationSeconds = 10,
    ) {
        $this->timeout = self::HARD_MAX_DURATION + 30;
    }

    public static function cacheKey(string $runId): string
    {
        return 'cache_service_monitor:'.$runId;
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return [
            'cache-monitor',
            'server:'.$this->serverId,
        ];
    }

    public function handle(
        ExecuteRemoteTaskOnServer $executor,
        CacheServiceAuditLogger $audit,
    ): void {
        $duration = max(self::MIN_DURATION, min(self::HARD_MAX_DURATION, $this->durationSeconds));
        $ttl = $duration + 60;

        $server = Server::query()->find($this->serverId);
        $row = ServerCacheService::query()->find($this->cacheServiceId);

        if ($server === null || $row === null) {
            $this->writeFinal(false, 0, __('Server or cache service not found.'), $ttl);

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->writeFinal(false, 0, __('MONITOR is not supported for :engine.', ['engine' => $row->engine]), $ttl);

            return;
        }

        $cli = CacheServiceStats::binaryFor($row->engine);
        // AUTH flag must come AFTER the cli binary, not before — otherwise
        // bash/timeout sees `-a 'pw' valkey-cli` as "run `-a` with these args"
        // and errors with "timeout: failed to run command '-a': No such file
        // or directory". `--no-auth-warning` keeps the safety warning off
        // stderr so it doesn't show up in the MONITOR stream.
        $authFlag = filled($row->auth_password ?? null)
            ? ' -a '.escapeshellarg((string) $row->auth_password).' --no-auth-warning'
            : '';

        // `timeout <N>` ensures we ALWAYS exit after the bounded window,
        // even if MONITOR's own client-side stop fails for any reason.
        // Exit code 124 is timeout's "we killed it normally" code; we
        // treat it as success for our purposes.
        //
        // `stdbuf -oL -eL` forces the cli's stdout/stderr into line-buffered
        // mode. Without it the cli block-buffers when piped over SSH, so on a
        // low-traffic engine the 4 KB buffer never flushes during the window
        // and the operator sees zero output even though MONITOR ran fine.
        $script = sprintf(
            'stdbuf -oL -eL timeout --preserve-status %d %s%s -p %d MONITOR 2>&1 || true',
            $duration,
            escapeshellarg($cli),
            $authFlag,
            (int) $row->port,
        );

        $audit->record(
            $server,
            ServerCacheServiceAuditEvent::EVENT_MONITOR_STARTED,
            ['engine' => $row->engine, 'name' => $row->name, 'duration_seconds' => $duration, 'run_id' => $this->runId],
        );

        Cache::put(self::cacheKey($this->runId), [
            'status' => 'running',
            'lines' => [],
            'started_at' => now()->timestamp,
            'duration_seconds' => $duration,
            'error' => null,
        ], $ttl);

        $bufferLines = [];
        $lineCount = 0;
        $lastFlush = 0.0;
        $flushIntervalSec = 0.4;

        try {
            $output = $executor->runInlineBashWithOutputCallback(
                $server,
                'cache-service:monitor:'.$row->engine,
                $script,
                function (string $type, string $chunk) use (&$bufferLines, &$lineCount, &$lastFlush, $flushIntervalSec, $ttl): void {
                    if ($chunk === '') {
                        return;
                    }

                    foreach (explode("\n", $chunk) as $line) {
                        if ($line === '') {
                            continue;
                        }
                        $bufferLines[] = $line;
                        $lineCount++;
                    }

                    if (count($bufferLines) > self::MAX_BUFFER_LINES) {
                        $bufferLines = array_slice($bufferLines, -self::MAX_BUFFER_LINES);
                    }

                    $now = microtime(true);
                    if ($now - $lastFlush >= $flushIntervalSec) {
                        $lastFlush = $now;
                        Cache::put(self::cacheKey($this->runId), [
                            'status' => 'running',
                            'lines' => $bufferLines,
                            'started_at' => Cache::get(self::cacheKey($this->runId).'.started', now()->timestamp),
                            'duration_seconds' => $this->durationSeconds,
                            'error' => null,
                        ], $ttl);
                    }

                    broadcast(new ServerCacheMonitorChunkBroadcast(
                        $this->serverId,
                        $this->runId,
                        $chunk,
                    ));
                },
                timeoutSeconds: $duration + 15,
                asRoot: false,
            );

            $exitCode = $output->exitCode;
            // 0 = MONITOR exited normally, 124 = timeout fired (our intent),
            // anything else = unexpected SSH/process failure. Treat 0 and 124
            // as success so the UI doesn't flash an error on the operator's
            // intentional bounded window.
            $success = in_array($exitCode, [0, 124], true);

            $audit->record(
                $server,
                $success
                    ? ServerCacheServiceAuditEvent::EVENT_MONITOR_COMPLETED
                    : ServerCacheServiceAuditEvent::EVENT_MONITOR_FAILED,
                ['engine' => $row->engine, 'name' => $row->name, 'duration_seconds' => $duration, 'run_id' => $this->runId, 'line_count' => $lineCount, 'exit_code' => $exitCode],
            );

            // Final flush of the buffer + completion state.
            Cache::put(self::cacheKey($this->runId), [
                'status' => $success ? 'completed' : 'failed',
                'lines' => $bufferLines,
                'started_at' => now()->subSeconds($duration)->timestamp,
                'duration_seconds' => $duration,
                'error' => $success ? null : __('MONITOR exited with code :code.', ['code' => $exitCode]),
            ], $ttl);

            broadcast(new ServerCacheMonitorCompletedBroadcast(
                $this->serverId,
                $this->runId,
                $success,
                $lineCount,
                $success ? null : __('MONITOR exited with code :code.', ['code' => $exitCode]),
            ));
        } catch (\Throwable $e) {
            $audit->record(
                $server,
                ServerCacheServiceAuditEvent::EVENT_MONITOR_FAILED,
                ['engine' => $row->engine, 'name' => $row->name, 'duration_seconds' => $duration, 'run_id' => $this->runId, 'error' => $e->getMessage()],
            );

            $this->writeFinal(false, $lineCount, $e->getMessage(), $ttl);
        }
    }

    private function writeFinal(bool $success, int $lineCount, ?string $error, int $ttl): void
    {
        Cache::put(self::cacheKey($this->runId), [
            'status' => $success ? 'completed' : 'failed',
            'lines' => [],
            'started_at' => now()->timestamp,
            'duration_seconds' => $this->durationSeconds,
            'error' => $error,
        ], $ttl);

        broadcast(new ServerCacheMonitorCompletedBroadcast(
            $this->serverId,
            $this->runId,
            $success,
            $lineCount,
            $error,
        ));
    }
}
