<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Insights\InsightHealthScoreService;
use App\Services\Insights\InsightRunCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Site-scoped counterpart to {@see RunServerInsightsJob}. When dispatched with a
 * `runId`, banner state is written to `site.meta` (not the server's meta) under
 * the same `insights_run.*` keys.
 */
class RunSiteInsightsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(
        public string $siteId,
        public ?string $onlyKey = null,
        public ?string $runId = null,
    ) {}

    public function handle(InsightRunCoordinator $coordinator, InsightHealthScoreService $healthScore): void
    {
        $site = Site::query()->with('server')->find($this->siteId);
        if ($site === null || $site->server === null || ! $site->server->isReady()) {
            return;
        }

        if ($this->runId === null) {
            $coordinator->runForSite($site, $this->onlyKey);
            $healthScore->computeAndStore($site->server);

            return;
        }

        $statusKey = (string) config('insights_workspace.meta_run_status_key');
        $finishedKey = (string) config('insights_workspace.meta_run_finished_at_key');
        $errorKey = (string) config('insights_workspace.meta_run_error_key');

        $this->updateMeta($site, [$statusKey => 'running']);

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
            ? sprintf('> Starting insights sweep on site %s …', $site->name)
            : sprintf('> Re-running [%s] on site %s …', $this->onlyKey, $site->name);
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
            $coordinator->runForSite($site, $this->onlyKey, $progress);

            $healthScore->computeAndStore($site->server);

            $append(sprintf(
                '> Done — %d candidate(s) recorded.',
                $totalCandidates,
            ));
            $flush(true);

            $this->updateMeta($site, [
                $statusKey => 'completed',
                $finishedKey => now()->toIso8601String(),
                $errorKey => null,
            ]);
        } catch (\Throwable $e) {
            $message = Str::limit(trim($e->getMessage()), 800) ?: 'Insights run failed.';
            $append('> ERROR: '.$message);
            $flush(true);

            $this->updateMeta($site, [
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
    private function updateMeta(Site $site, array $patch): void
    {
        $fresh = $site->fresh();
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
