<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Livewire\Servers\WorkspaceCaches;
use App\Models\ServerCacheService;
use App\Support\Servers\CacheServiceMemoryConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Background read of maxmemory + maxmemory-policy for the Configure subtab's
 * Memory limits card. Livewire dispatches this and reads the cached result
 * via {@see resultCacheKey()}; SSH never runs inline so PHP's 30s
 * max_execution_time can't bite the Livewire update commit.
 *
 * @see WorkspaceCaches::loadCacheMemorySettings()
 */
class RefreshCacheMemorySettingsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(
        public string $cacheServiceId,
    ) {}

    public static function resultCacheKey(string $serverId, string $engine): string
    {
        return sprintf('dply.cache_workspace.refresh.memory_settings.%s.%s', $serverId, $engine);
    }

    public function handle(CacheServiceMemoryConfig $memory): void
    {
        $row = ServerCacheService::query()->with('server')->find($this->cacheServiceId);
        if ($row === null || $row->server === null) {
            return;
        }

        try {
            $current = $memory->read($row->server, $row);
            Cache::put(
                self::resultCacheKey($row->server->id, $row->engine),
                [
                    'ok' => true,
                    'maxmemory' => (string) ($current['maxmemory'] ?? ''),
                    'maxmemory_policy' => (string) ($current['maxmemory_policy'] ?? 'noeviction'),
                    'at' => now()->toIso8601String(),
                ],
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
