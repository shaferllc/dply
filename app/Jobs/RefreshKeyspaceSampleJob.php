<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ServerCacheService;
use App\Support\Servers\CacheServiceKeyspaceSampler;
use App\Support\Servers\CacheServiceStats;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Background INFO keyspace sample. Pulls a single INFO buffer over SSH, runs
 * it through {@see CacheServiceKeyspaceSampler} (which computes delta windows
 * against the previous cached sample), and writes the new sample to the
 * result cache. Livewire's poll tick reads from there — never blocks on SSH.
 *
 * Carries the previous sample forward so the sampler can compute ops/sec and
 * hit-rate window deltas without the Livewire component needing to look at
 * its own buffer (it would be stale across the dispatch boundary anyway).
 *
 * @see \App\Livewire\Servers\WorkspaceCaches::loadKeyspaceDashboard()
 */
class RefreshKeyspaceSampleJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    /**
     * @param  array<string, mixed>|null  $previousSample  Used by the sampler to compute window deltas.
     */
    public function __construct(
        public string $cacheServiceId,
        public ?array $previousSample = null,
    ) {
    }

    public static function resultCacheKey(string $serverId, string $engine): string
    {
        return sprintf('dply.cache_workspace.refresh.keyspace_sample.%s.%s', $serverId, $engine);
    }

    public function handle(CacheServiceStats $stats, CacheServiceKeyspaceSampler $sampler): void
    {
        $row = ServerCacheService::query()->with('server')->find($this->cacheServiceId);
        if ($row === null || $row->server === null) {
            return;
        }

        try {
            $raw = $stats->rawInfo($row->server, $row);
            if ($raw === null) {
                Cache::put(
                    self::resultCacheKey($row->server->id, $row->engine),
                    ['ok' => false, 'error' => __('Could not read INFO from :engine.', ['engine' => $row->engine])->__toString(), 'at' => now()->toIso8601String()],
                    now()->addHour(),
                );

                return;
            }

            $sample = $sampler->sample($raw, $this->previousSample);
            Cache::put(
                self::resultCacheKey($row->server->id, $row->engine),
                ['ok' => true, 'sample' => $sample, 'at' => now()->toIso8601String()],
                now()->addHour(),
            );
        } catch (\Throwable $e) {
            Cache::put(
                self::resultCacheKey($row->server->id, $row->engine),
                ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 500), 'at' => now()->toIso8601String()],
                now()->addHour(),
            );
        }
    }
}
