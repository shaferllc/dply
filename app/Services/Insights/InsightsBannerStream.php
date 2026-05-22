<?php

declare(strict_types=1);

namespace App\Services\Insights;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Owns the banner cache buffer + per-entity meta-state writes for an apply-fix
 * or revert-fix job. Mirrors the inline buffer/flush pattern in
 * {@see \App\Jobs\SyncAuthorizedKeysJob} and {@see \App\Jobs\ApplyFirewallJob},
 * with two adjustments: (a) state is written to either {@see Server}.meta or
 * {@see Site}.meta depending on the finding's scope; (b) the helper exposes
 * succeed/fail/refuse sinks so callers stay linear.
 */
class InsightsBannerStream
{
    /** @var list<string> */
    private array $lines = [];

    private float $lastFlush = 0.0;

    private const FLUSH_INTERVAL_SEC = 0.4;

    private int $maxLines;

    public function __construct(
        private Server|Site $entity,
        private string $runId,
        private int $findingId,
        private string $statusKeyConfig,
        private string $finishedKeyConfig,
        private string $errorKeyConfig,
        private string $cachePrefixConfig,
        private string $cacheTtlConfig,
    ) {
        $this->maxLines = (int) config('insights_workspace.max_buffer_lines', 500);
        $this->writeStatus('running', null, null);
    }

    public function append(string $line): void
    {
        if ($line === '') {
            return;
        }
        $this->lines[] = $line;
        if (count($this->lines) > $this->maxLines) {
            $this->lines = array_slice($this->lines, -$this->maxLines);
        }
        $this->flush(false);
    }

    public function appendBlock(string $block): void
    {
        foreach (preg_split("/\r?\n/", $block) ?: [] as $line) {
            if ($line !== '') {
                $this->append($line);
            }
        }
    }

    public function succeed(): void
    {
        $this->flush(true);
        $this->writeStatus('completed', now()->toIso8601String(), null);
    }

    public function fail(string $reason): void
    {
        $this->flush(true);
        $this->writeStatus('failed', now()->toIso8601String(), Str::limit($reason, 800));
    }

    public function refuse(string $reason): void
    {
        $this->flush(true);
        $this->writeStatus('refused', now()->toIso8601String(), Str::limit($reason, 800));
    }

    private function flush(bool $force): void
    {
        $now = microtime(true);
        if (! $force && ($now - $this->lastFlush) < self::FLUSH_INTERVAL_SEC) {
            return;
        }
        $this->lastFlush = $now;

        $prefix = (string) config($this->cachePrefixConfig);
        $ttl = (int) config($this->cacheTtlConfig, 300);
        Cache::put($prefix.$this->runId, [
            'lines' => $this->lines,
            'updated_at' => now()->timestamp,
        ], $ttl);
    }

    private function writeStatus(string $status, ?string $finishedAt, ?string $error): void
    {
        $fresh = $this->entity->fresh();
        if ($fresh === null) {
            return;
        }
        $meta = $fresh->meta ?? [];
        $meta[(string) config($this->statusKeyConfig)] = $status;
        if ($finishedAt !== null) {
            $meta[(string) config($this->finishedKeyConfig)] = $finishedAt;
        }
        $meta[(string) config($this->errorKeyConfig)] = $error;
        $fresh->update(['meta' => $meta]);
    }
}
