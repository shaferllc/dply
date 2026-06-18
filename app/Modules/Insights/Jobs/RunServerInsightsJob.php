<?php

namespace App\Modules\Insights\Jobs;

use App\Models\Server;
use App\Modules\Insights\Services\InsightHealthScoreService;
use App\Modules\Insights\Services\InsightRunCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Run insight checks on a server in the background.
 *
 * When invoked with a `runId`, the job streams lifecycle output back to the
 * workspace banner via the application cache (keyed by run_id) and writes
 * run state under `insights_run.*` keys on `server.meta`. When invoked
 * without a runId — e.g. scheduled sweeps, post-deploy / post-setup hooks —
 * the job runs silently the way it always has, with no banner side effects.
 */
class RunServerInsightsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(
        public string $serverId,
        public ?string $onlyKey = null,
        public ?string $runId = null,
    ) {}

    public function handle(InsightRunCoordinator $coordinator, InsightHealthScoreService $healthScore): void
    {
        $server = Server::find($this->serverId);
        if ($server === null || ! $server->isReady()) {
            return;
        }

        if ($this->runId === null) {
            $coordinator->runForServer($server, $this->onlyKey);
            if ($this->onlyKey === null) {
                $healthScore->computeAndStore($server);
            }

            return;
        }

        $statusKey = (string) config('insights_workspace.meta_run_status_key');
        $finishedKey = (string) config('insights_workspace.meta_run_finished_at_key');
        $errorKey = (string) config('insights_workspace.meta_run_error_key');

        $this->updateMeta($server, [$statusKey => 'running']);

        $bufferLines = [];
        $cacheKey = $this->cacheKey();
        $ttl = (int) config('insights_workspace.run_output_cache_ttl_seconds', 300);
        $maxLines = (int) config('insights_workspace.max_buffer_lines', 500);
        $lastFlush = 0.0;
        $flushIntervalSec = 0.4;

        $flush = function (bool $force = false) use (&$bufferLines, &$lastFlush, $flushIntervalSec, $cacheKey, $ttl): void {
            $now = microtime(true);
            if (! $force && ($now - $lastFlush) < $flushIntervalSec) {
                return;
            }
            $lastFlush = $now;
            Cache::put($cacheKey, [
                'lines' => $bufferLines,
                'updated_at' => now()->timestamp,
            ], $ttl);
        };

        $append = function (string $line) use (&$bufferLines, $maxLines, $flush): void {
            $bufferLines[] = $line;
            if (count($bufferLines) > $maxLines) {
                $bufferLines = array_slice($bufferLines, -$maxLines);
            }
            $flush();
        };

        $bufferLines[] = $this->onlyKey === null
            ? '> Starting insights sweep on '.$server->getSshConnectionString().' …'
            : sprintf('> Re-running [%s] on %s …', $this->onlyKey, $server->getSshConnectionString());
        $flush(true);

        $totalCandidates = 0;

        $progress = function (string $event, string $key, array $context) use ($append, &$totalCandidates): void {
            switch ($event) {
                case 'check.start':
                    $append(sprintf('> [%s] running…', $key));
                    break;
                case 'check.complete':
                    $count = (int) ($context['candidates'] ?? 0);
                    $totalCandidates += $count;
                    $append(sprintf(
                        '> [%s] %s',
                        $key,
                        $count === 0 ? 'ok (no findings)' : sprintf('%d candidate(s)', $count),
                    ));
                    break;
                case 'check.error':
                    $msg = (string) ($context['message'] ?? '');
                    $append(sprintf('> [%s] ERROR: %s', $key, Str::limit($msg, 400)));
                    break;
            }
        };

        try {
            $coordinator->runForServer($server, $this->onlyKey, $progress);

            if ($this->onlyKey === null) {
                $healthScore->computeAndStore($server);
            }

            $append(sprintf(
                '> Done — %d candidate(s) recorded.',
                $totalCandidates,
            ));
            $flush(true);

            $this->updateMeta($server, [
                $statusKey => 'completed',
                $finishedKey => now()->toIso8601String(),
                $errorKey => null,
            ]);
        } catch (\Throwable $e) {
            $message = Str::limit(trim($e->getMessage()), 800) ?: 'Insights run failed.';
            $append('> ERROR: '.$message);
            $flush(true);

            $this->updateMeta($server, [
                $statusKey => 'failed',
                $finishedKey => now()->toIso8601String(),
                $errorKey => $message,
            ]);
        }
    }

    public function cacheKey(): string
    {
        $prefix = (string) config('insights_workspace.run_output_cache_key_prefix', 'insights_run_output:');

        return $prefix.((string) $this->runId);
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function updateMeta(Server $server, array $patch): void
    {
        $fresh = $server->fresh();
        if ($fresh === null) {
            return;
        }
        $meta = $fresh->meta ?? [];
        foreach ($patch as $k => $v) {
            $meta[$k] = $v;
        }
        $fresh->update(['meta' => $meta]);
    }
}
