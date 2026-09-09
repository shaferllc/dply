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
     * @param  Collection<int, ServerCacheService>  $cacheServices
     * @return array<string, mixed>
     */
    public static function for(Server $server, WorkspaceCaches $component, Collection $cacheServices): array
    {
        $card = 'border-b border-brand-ink/10';
        $opsReady = $server->isReady() && $server->ssh_private_key;
        // Gated engines are hidden outright, not badged "Soon". An engine that
        // is already installed on THIS server stays listed regardless of its
        // flag — otherwise parking an engine would strip the only UI for
        // managing or uninstalling an existing service.
        $engines = array_values(array_filter(
            ['redis', 'valkey', 'memcached', 'keydb', 'dragonfly'],
            fn (string $engine): bool => CacheEngineAvailability::isAvailable($engine)
                || $cacheServices->contains(fn (ServerCacheService $row): bool => $row->engine === $engine),
        ));

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

        // Engine => coming-soon bool. Redis is always available; the rest are
        // gated behind cache.{engine} flags. Drives the Soon badge on the tab
        // strip + the coming-soon teaser in the engine panel.
        $comingSoonEngines = CacheEngineAvailability::comingSoonMap($engines);

        return compact(
            'card',
            'opsReady',
            'engines',
            'engineLabels',
            'engineDescriptions',
            'busyService',
            'cacheBusy',
            'comingSoonEngines',
        );
    }
}
