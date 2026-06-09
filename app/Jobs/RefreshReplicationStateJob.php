<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ServerCacheService;
use App\Support\Servers\CacheServiceReplication;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Background INFO replication refresh. Livewire dispatches this from the
 * Caches workspace Stats subtab and reads the result via {@see resultCacheKey()};
 * SSH never runs inline so PHP's 30s max_execution_time can't bite the
 * Livewire update commit.
 *
 * @see \App\Livewire\Servers\WorkspaceCaches::loadReplicationState()
 */
class RefreshReplicationStateJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(
        public string $cacheServiceId,
    ) {
    }

    public static function resultCacheKey(string $serverId, string $engine): string
    {
        return sprintf('dply.cache_workspace.refresh.replication.%s.%s', $serverId, $engine);
    }

    public function handle(CacheServiceReplication $replication): void
    {
        $row = ServerCacheService::query()->with('server')->find($this->cacheServiceId);
        if ($row === null || $row->server === null) {
            return;
        }

        try {
            $state = $replication->snapshot($row->server, $row);
            Cache::put(
                self::resultCacheKey($row->server->id, $row->engine),
                ['ok' => true, 'state' => $state, 'at' => now()->toIso8601String()],
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
