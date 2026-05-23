<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Livewire\Servers\WorkspaceCaches;
use App\Models\Server;
use App\Models\ServerCacheService;
use Illuminate\Support\Collection;

/**
 * View-model for the server Caches workspace blade tree. Keeps catalog/setup
 * out of {@see resources/views/livewire/servers/workspace-caches.blade.php}.
 */
final class CacheWorkspaceViewData
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Server $server, WorkspaceCaches $component, Collection $cacheServices): array
    {
        $card = 'dply-card overflow-hidden';
        $opsReady = $server->isReady() && $server->ssh_private_key;
        $engines = ['redis', 'valkey', 'memcached', 'keydb', 'dragonfly'];

        $engineLabels = [
            'redis' => 'Redis',
            'valkey' => 'Valkey',
            'memcached' => 'Memcached',
            'keydb' => 'KeyDB',
            'dragonfly' => 'Dragonfly',
        ];

        $engineDescriptions = [
            'redis' => __('In-memory data structure store; the most widely-deployed cache for PHP/Laravel apps.'),
            'valkey' => __('Open-source fork of Redis maintained by the Linux Foundation; wire-compatible with Redis clients.'),
            'memcached' => __('Lightweight key-value cache. Smaller feature set than Redis but very low overhead.'),
            'keydb' => __('Multi-threaded Redis fork. Higher throughput on multi-core boxes; same wire protocol as Redis.'),
            'dragonfly' => __('Modern in-memory store with Redis wire compatibility and lower memory overhead.'),
        ];

        $busyService = $cacheServices->first(fn (ServerCacheService $row): bool => in_array($row->status, [
            ServerCacheService::STATUS_PENDING,
            ServerCacheService::STATUS_INSTALLING,
            ServerCacheService::STATUS_UNINSTALLING,
        ], true));
        $cacheBusy = $busyService !== null;

        return compact(
            'card',
            'opsReady',
            'engines',
            'engineLabels',
            'engineDescriptions',
            'busyService',
            'cacheBusy',
        );
    }
}
