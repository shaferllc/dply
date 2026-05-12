<?php

declare(strict_types=1);

return [
    /**
     * Cache the per-server cache-service probe so the WorkspaceCaches render path doesn't run an
     * SSH round-trip on every Livewire update. Recheck button busts this manually.
     */
    'capabilities_cache_ttl_seconds' => (int) env('SERVER_CACHE_CAPABILITIES_TTL', 120),

    /**
     * Cache TTL for the per-server distro probe (ID + codename from /etc/os-release). Codename
     * doesn't change for the lifetime of a server, so 24h is generous; force a refresh via
     * ServerCacheServiceHostCapabilities::forgetDistro() if you rebuild the box in-place.
     */
    'distro_cache_ttl_seconds' => (int) env('SERVER_CACHE_DISTRO_TTL', 86_400),

    /**
     * Optional dedicated queue for cache-service install / uninstall jobs. Horizon must list this
     * queue (see config/horizon.php) for the workers to pick it up; default queue is used when unset.
     */
    'install_queue' => env('SERVER_CACHE_INSTALL_QUEUE'),

    /**
     * Pinned Dragonfly release tag installed by the bootstrap script. Dragonfly publishes only
     * GitHub-release .deb artifacts (no apt repo), so install determinism is up to us: bumping
     * this requires re-validating against each supported distro codename
     * (see CacheServiceInstallScripts::supportedDistroCodenames('dragonfly')). Override per-env
     * with DPLY_DRAGONFLY_VERSION when testing a newer build before flipping the default.
     */
    'dragonfly_version' => env('DPLY_DRAGONFLY_VERSION', 'v1.38.1'),
];
