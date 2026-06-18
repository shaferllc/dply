<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Livewire\Servers\WorkspaceCaches;
use App\Models\ServerCacheService;
use App\Support\Servers\CacheServiceSlowlog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Background SLOWLOG GET refresh. Livewire dispatches this from the Caches
 * workspace Stats subtab and reads the result via {@see resultCacheKey()};
 * SSH never runs inline so PHP's 30s max_execution_time can't bite the
 * Livewire update commit.
 *
 * @see WorkspaceCaches::loadSlowlog()
 */
class RefreshSlowlogJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(
        public string $cacheServiceId,
    ) {}

    public static function resultCacheKey(string $serverId, string $engine): string
    {
        return sprintf('dply.cache_workspace.refresh.slowlog.%s.%s', $serverId, $engine);
    }

    public function handle(CacheServiceSlowlog $slowlog): void
    {
        $row = ServerCacheService::query()->with('server')->find($this->cacheServiceId);
        if ($row === null || $row->server === null) {
            return;
        }

        try {
            $entries = $slowlog->entries($row->server, $row);
            Cache::put(
                self::resultCacheKey($row->server->id, $row->engine),
                ['ok' => true, 'entries' => $entries, 'at' => now()->toIso8601String()],
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
