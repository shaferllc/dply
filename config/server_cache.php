<?php

declare(strict_types=1);

return [
    /**
     * Cache the per-server cache-service probe so the WorkspaceCaches render path doesn't run an
     * SSH round-trip on every Livewire update. Recheck button busts this manually.
     */
    'capabilities_cache_ttl_seconds' => (int) env('SERVER_CACHE_CAPABILITIES_TTL', 120),

    /**
     * Optional dedicated queue for cache-service install / uninstall jobs. Horizon must list this
     * queue (see config/horizon.php) for the workers to pick it up; default queue is used when unset.
     */
    'install_queue' => env('SERVER_CACHE_INSTALL_QUEUE'),
];
