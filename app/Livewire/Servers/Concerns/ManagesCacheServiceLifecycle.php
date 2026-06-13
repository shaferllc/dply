<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\InstallCacheServiceJob;
use App\Jobs\RecheckCacheServiceJob;
use App\Jobs\RefreshCacheClientsJob;
use App\Jobs\RefreshCacheMemorySettingsJob;
use App\Jobs\RefreshReplicationStateJob;
use App\Jobs\RefreshSlowlogJob;
use App\Jobs\StatusCacheServiceJob;
use App\Jobs\UninstallCacheServiceJob;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerCacheServiceAuditEvent;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\CacheServiceAuditLogger;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\CacheEngineAvailability;
use App\Support\Servers\CacheEngineInfo;
use App\Support\Servers\CacheServiceInstallScripts;
use App\Support\Servers\CacheServiceStats;
use App\Support\Servers\ServerCacheServiceHostCapabilities;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCacheServiceLifecycle
{
    use ManagesCacheStatusModal;
    use RunsCacheServiceActions;

    /**
     * Engine reachability + distro-support gates, SSH-probed off the render path
     * (wire:init → loadCacheCapabilities) so the workspace paints instantly.
     * $capabilitiesLoaded gates the "checking…" UI.
     *
     * @var array<string, bool>
     */
    public array $capabilities_state = [];

    /** @var array<string, string|null> */
    public array $cache_unsupported_reasons = [];

    public bool $capabilitiesLoaded = false;

    /**
     * Active instance name within the current per-engine tab. Historically URL-bound so deep
     * links to a named instance worked; with multi-instance retired (one row per engine, name
     * always `'default'`) this stays as a const-shaped property so legacy reads
     * (`$row->name === $this->active_instance`) keep working without rewriting every call site.
     */
    public string $active_instance = ServerCacheService::DEFAULT_INSTANCE_NAME;

    /**
     * Status/Logs modal for the active cache instance. Lets operators inspect a
     * specific instance's systemd state without dropping to SSH. Mirrors the
     * shape of the Services workspace status modal, but scoped to caches so we
     * don't drag in the unrelated state of `ManagesServerSystemdServices`.
     * Properties are cache-prefixed in case both concerns ever co-exist.
     */
    public bool $showCacheStatusModal = false;

    public string $cacheStatusModalEngine = '';

    public string $cacheStatusModalInstance = '';

    public string $cacheStatusModalUnit = '';

    /** Either 'status' (systemctl status) or 'logs' (journalctl -u …). */
    public string $cacheStatusModalView = 'status';

    public string $cacheStatusModalOutput = '';

    public bool $cacheStatusModalLoading = false;

    public ?string $cacheStatusModalError = null;


}
