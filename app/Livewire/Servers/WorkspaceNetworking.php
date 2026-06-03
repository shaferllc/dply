<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Jobs\AttachServerToNetworkJob;
use App\Jobs\CreateProviderNetworkJob;
use App\Jobs\ToggleDatabaseNetworkingJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseEngine;
use App\Services\DigitalOceanService;
use App\Services\HetznerService;
use App\Support\Servers\CacheServiceNetworkExposure;
use App\Support\Servers\DatabaseEngineInstallScripts;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Server-level networking workspace: one place to see and manage every
 * service that is (or could be) exposed to the network — PostgreSQL databases,
 * MySQL databases, Redis/Valkey cache engines.
 *
 * Per-database access is the same job ({@see ToggleDatabaseNetworkingJob}) used
 * by the database engine Networking subtab; this page is just a cross-engine
 * aggregate view so operators don't have to hop between workspaces.
 */
class WorkspaceNetworking extends Component
{
    use AuthorizesRequests;
    use ConfirmsActionWithModal;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    public Server $server;

    /** CIDR inputs keyed by database ID. */
    public array $db_networking_allowed_from = [];

    /** Selected jump-host server ID per database ID (jump-host access helper). */
    public array $db_jump_host = [];

    /** Chosen local tunnel port per database ID (jump-host access helper). */
    public array $db_jump_local_port = [];

    /** Network ID inputs keyed by server ID (for attach-to-network forms). */
    public array $attach_network_id = [];

    /** Available Hetzner networks for the attach dropdown — loaded on demand. */
    public array $hetzner_networks = [];

    public bool $hetzner_networks_loading = false;

    /** State for the "Create network" modal. */
    public string $new_network_name = '';

    public string $new_network_ip_range = '10.0.0.0/8';

    /** dply Server IDs to attach when creating a new network. */
    public array $new_network_server_ids = [];

    /** CIDR inputs keyed by cache service ID (for inline cache expose form). */
    public array $cache_networking_allowed_from = [];

    // ── Network routes ────────────────────────────────────────────────────────
    public string $route_destination = '';

    public string $route_gateway = '';

    public function mount(Server $server): void
    {
        $this->server = $server;
    }

    public function loadHetznerNetworks(): void
    {
        $this->authorize('update', $this->server);

        $credential = $this->server->providerCredential;
        if (! $credential) {
            $this->toastError(__('No Hetzner credential found.'));

            return;
        }

        $this->hetzner_networks_loading = true;

        try {
            $hetzner = new HetznerService($credential);
            $this->hetzner_networks = $hetzner->listNetworks();
        } catch (\Throwable) {
            $this->hetzner_networks = [];
        }

        $this->hetzner_networks_loading = false;
    }

    /**
     * Sync the private IP for a DigitalOcean server from the provider API.
     * DO assigns private IPs at creation; this covers servers created before the
     * private_ip_address column existed, or servers moved between VPCs.
     */
    public function syncPrivateIp(string $serverId): void
    {
        $this->authorize('update', $this->server);

        $target = $serverId === $this->server->id
            ? $this->server
            : Server::query()->where('organization_id', $this->server->organization_id)->find($serverId);

        if (! $target || $target->provider->value !== 'digitalocean') {
            $this->toastError(__('Server not found or not a DigitalOcean server.'));

            return;
        }

        $credential = $target->providerCredential;
        if (! $credential) {
            $this->toastError(__('No DigitalOcean credential found.'));

            return;
        }

        try {
            $do = new DigitalOceanService($credential);
            $droplet = $do->getDroplet((int) $target->provider_id);
            $privateIp = DigitalOceanService::getDropletPrivateIp($droplet);

            if ($privateIp) {
                $target->update(['private_ip_address' => $privateIp]);
                $this->toastSuccess(__('Private IP :ip synced for :name.', ['ip' => $privateIp, 'name' => $target->name]));
            } else {
                $this->toastError(__('No private IP found — make sure :name is in a VPC.', ['name' => $target->name]));
            }
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    /**
     * Detach a Hetzner server from its private network and clear the stored private IP.
     */
    public function detachFromNetwork(string $serverId): void
    {
        $this->authorize('update', $this->server);

        $target = $serverId === $this->server->id
            ? $this->server
            : Server::query()->where('organization_id', $this->server->organization_id)->find($serverId);

        if (! $target || $target->provider->value !== 'hetzner') {
            $this->toastError(__('Server not found or not a Hetzner server.'));

            return;
        }

        $networkId = (int) $target->hetzner_network_id;
        if ($networkId === 0) {
            $this->toastError(__(':name has no network attached.', ['name' => $target->name]));

            return;
        }

        $credential = $target->providerCredential;
        if (! $credential) {
            $this->toastError(__('No Hetzner credential found.'));

            return;
        }

        try {
            $hetzner = new HetznerService($credential);
            $hetzner->detachServerFromNetwork((int) $target->provider_id, $networkId);
        } catch (\Throwable $e) {
            // 404/409 = already detached — treat as success so the row gets cleaned up.
            if (! str_contains($e->getMessage(), '404') && ! str_contains($e->getMessage(), '409')) {
                $this->toastError($e->getMessage());

                return;
            }
        }

        $target->update([
            'private_ip_address' => null,
            'hetzner_network_id' => null,
        ]);

        $this->toastSuccess(__(':name detached from network — private IP cleared.', ['name' => $target->name]));
    }

    /**
     * Expose a cache service to the network from the Networking page.
     * Delegates to {@see CacheServiceNetworkExposure} — same logic as the Caches workspace.
     */
    public function exposeCacheToNetwork(string $cacheId, CacheServiceNetworkExposure $exposure): void
    {
        $this->authorize('update', $this->server);

        $row = ServerCacheService::query()->where('server_id', $this->server->id)->find($cacheId);
        if (! $row) {
            $this->toastError(__('Cache service not found.'));

            return;
        }

        if (! ServerCacheService::engineSupportsAuth($row->engine)) {
            $this->toastError(__('Network exposure only supports Redis-family engines.'));

            return;
        }

        if (empty($row->auth_password)) {
            $this->toastError(__('Set an AUTH password first — exposing an un-authenticated cache to the network is not allowed.'));

            return;
        }

        $cidr = trim($this->cache_networking_allowed_from[$cacheId] ?? '');
        if ($cidr === '') {
            $this->addError('cache_networking_allowed_from.'.$cacheId, __('Enter a CIDR (e.g. 10.0.0.0/8).'));

            return;
        }

        try {
            $exposure->expose($row->server, $row, $cidr, auth()->id());
            $this->cache_networking_allowed_from[$cacheId] = '';
            $this->toastSuccess(__(':engine exposed from :cidr — firewall apply queued.', ['engine' => ucfirst($row->engine), 'cidr' => $cidr]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    /**
     * Lock down a cache service back to localhost-only.
     */
    public function lockdownCache(string $cacheId, CacheServiceNetworkExposure $exposure): void
    {
        $this->authorize('update', $this->server);

        $row = ServerCacheService::query()->where('server_id', $this->server->id)->find($cacheId);
        if (! $row) {
            $this->toastError(__('Cache service not found.'));

            return;
        }

        try {
            $exposure->lockdown($row->server, $row, auth()->id());
            $this->toastSuccess(__(':engine locked down to localhost — firewall rule removed.', ['engine' => ucfirst($row->engine)]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function toggleDatabaseNetworking(string $databaseId, bool $enable): void
    {
        $this->authorize('update', $this->server);

        $db = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->find($databaseId);

        if (! $db) {
            $this->toastError(__('Database not found.'));

            return;
        }

        if (! DatabaseEngineInstallScripts::supportsRemoteAccess($db->engine)) {
            $this->toastError(__('Remote access is not supported for :engine.', ['engine' => $db->engine]));

            return;
        }

        $allowedFrom = $enable ? trim($this->db_networking_allowed_from[$databaseId] ?? '0.0.0.0/0') : '';

        if ($enable) {
            if ($allowedFrom === '') {
                $allowedFrom = '0.0.0.0/0';
            }

            if (! $this->isValidCidr($allowedFrom)) {
                $this->addError('db_networking_allowed_from.'.$databaseId, __('Enter a valid CIDR (e.g. 0.0.0.0/0, 10.0.0.0/8).'));

                return;
            }
        }

        $db->update([
            'remote_access' => $enable,
            'allowed_from' => $enable ? $allowedFrom : null,
        ]);

        ToggleDatabaseNetworkingJob::dispatch($databaseId, $enable, $allowedFrom, auth()->id());

        $this->toastSuccess(
            $enable
                ? __('Enabling remote access for :name — progress shows in the banner above.', ['name' => $db->name])
                : __('Disabling remote access for :name — progress shows in the banner above.', ['name' => $db->name])
        );
    }

    /**
     * Create a new Hetzner private network and attach the selected servers to it.
     * Dispatches CreateProviderNetworkJob which polls until private IPs are assigned.
     */
    public function createNetwork(): void
    {
        $this->authorize('update', $this->server);

        $name = trim($this->new_network_name);
        $ipRange = trim($this->new_network_ip_range);

        if ($name === '') {
            $this->addError('new_network_name', __('Enter a name for the network.'));

            return;
        }

        if ($ipRange === '' || ! $this->isValidCidr($ipRange)) {
            $this->addError('new_network_ip_range', __('Enter a valid CIDR (e.g. 10.0.0.0/8).'));

            return;
        }

        if (empty($this->new_network_server_ids)) {
            $this->addError('new_network_server_ids', __('Select at least one server to attach.'));

            return;
        }

        // Validate all selected servers are Hetzner servers in this org.
        $serverIds = Server::query()
            ->whereIn('id', $this->new_network_server_ids)
            ->where('organization_id', $this->server->organization_id)
            ->where('provider', 'hetzner')
            ->pluck('id')
            ->all();

        if (empty($serverIds)) {
            $this->addError('new_network_server_ids', __('No valid Hetzner servers selected.'));

            return;
        }

        $credential = $this->server->providerCredential;
        if (! $credential) {
            $this->toastError(__('No Hetzner credential found for this server.'));

            return;
        }

        CreateProviderNetworkJob::dispatch(
            $credential->id,
            $name,
            $ipRange,
            $serverIds,
        );

        $this->dispatch('close-modal', 'create-network-modal');
        $this->new_network_name = '';
        $this->new_network_server_ids = [];

        $this->toastSuccess(__('Creating network ":name" and attaching servers — private IPs will appear here once assigned (takes ~30 seconds).', ['name' => $name]));
    }

    /**
     * Attach a server (this server or a peer) to a Hetzner private network.
     * Hetzner-only — DO does not support post-creation VPC attachment.
     */
    public function attachToNetwork(string $serverId): void
    {
        $this->authorize('update', $this->server);

        $target = $serverId === $this->server->id
            ? $this->server
            : Server::query()
                ->where('organization_id', $this->server->organization_id)
                ->find($serverId);

        if (! $target) {
            $this->toastError(__('Server not found.'));

            return;
        }

        if ($target->provider->value !== 'hetzner') {
            $this->toastError(__('Network attachment is only available for Hetzner servers. DigitalOcean VPCs must be assigned at creation time.'));

            return;
        }

        $networkId = (int) trim((string) ($this->attach_network_id[$serverId] ?? ''));

        if ($networkId <= 0) {
            $this->addError('attach_network_id.'.$serverId, __('Enter a valid Hetzner Network ID.'));

            return;
        }

        AttachServerToNetworkJob::dispatch($target->id, $networkId);

        $this->dispatch('close-modal', 'attach-network-modal-'.$serverId);
        $this->attach_network_id[$serverId] = '';

        $this->toastSuccess(__('Attaching :server to network :id — private IP will appear here once assigned (~30s).', [
            'server' => $target->name,
            'id' => $networkId,
        ]));
    }

    private function isValidCidr(string $value): bool
    {
        if ($value === '' || $value === 'any') {
            return false;
        }

        $parts = array_filter(array_map('trim', explode(',', $value)));
        foreach ($parts as $part) {
            if (! str_contains($part, '/')) {
                return false;
            }
            [$ip, $prefix] = explode('/', $part, 2);
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                return false;
            }
            if (! is_numeric($prefix) || (int) $prefix < 0 || (int) $prefix > 128) {
                return false;
            }
        }

        return true;
    }

    public function render(): View
    {
        // All ready servers in this org except the current one — potential network peers.
        $peerServers = Server::query()
            ->where('organization_id', $this->server->organization_id)
            ->where('id', '!=', $this->server->id)
            ->where('status', Server::STATUS_READY)
            ->whereNotIn('provider', ['digitalocean_functions', 'aws_lambda'])
            ->orderBy('name')
            ->get();

        $peerServerIds = $peerServers->pluck('id')->all();

        // Load database engines + databases for all peers + this server.
        $allServerIds = array_merge([$this->server->id], $peerServerIds);

        $databaseEnginesByServer = ServerDatabaseEngine::query()
            ->whereIn('server_id', $allServerIds)
            ->where('status', ServerDatabaseEngine::STATUS_RUNNING)
            ->whereIn('engine', ['postgres', 'mysql', 'mariadb'])
            ->get()
            ->groupBy('server_id');

        $databasesByServer = ServerDatabase::query()
            ->whereIn('server_id', $allServerIds)
            ->whereIn('engine', ['postgres', 'mysql', 'mariadb'])
            ->get()
            ->groupBy('server_id');

        $cacheServicesByServer = ServerCacheService::query()
            ->whereIn('server_id', $allServerIds)
            ->where('status', ServerCacheService::STATUS_RUNNING)
            ->get()
            ->groupBy('server_id');

        // This server's own data for the per-database networking controls.
        $databaseEngines = $databaseEnginesByServer->get($this->server->id, collect());
        $databasesByEngine = ($databasesByServer->get($this->server->id, collect()))->groupBy('engine');
        $cacheServices = $cacheServicesByServer->get($this->server->id, collect());

        // Resolve cache exposure state for inline controls (#3).
        $exposure = app(CacheServiceNetworkExposure::class);
        $cacheExposureByService = $cacheServices->mapWithKeys(fn ($row) => [
            $row->id => $exposure->resolveExposure($row),
        ])->all();

        // Load routes for this server's Hetzner private network (if any).
        $networkRoutes = [];
        $networkInfo = null;
        $networkId = (int) ($this->server->hetzner_network_id ?? 0);

        if ($networkId > 0 && $this->server->provider->value === 'hetzner') {
            $credential = $this->server->providerCredential;
            if ($credential) {
                try {
                    $hetzner = new HetznerService($credential);
                    $networkInfo = $hetzner->getNetwork($networkId);
                    $networkRoutes = $networkInfo['routes'] ?? [];
                } catch (\Throwable) {
                    // API unavailable — show empty routes, don't crash the page.
                }
            }
        }

        return view('livewire.servers.workspace-networking', [
            'peerServers' => $peerServers,
            'databaseEnginesByServer' => $databaseEnginesByServer,
            'databasesByServer' => $databasesByServer,
            'cacheServicesByServer' => $cacheServicesByServer,
            // Current server's own data for the networking controls section.
            'databaseEngines' => $databaseEngines,
            'databasesByEngine' => $databasesByEngine,
            'cacheServices' => $cacheServices,
            'cacheExposureByService' => $cacheExposureByService,
            'networkRoutes' => $networkRoutes,
            'networkInfo' => $networkInfo,
            'networkId' => $networkId,
        ]);
    }

    // ── Route management ──────────────────────────────────────────────────────

    public function addRoute(): void
    {
        $this->authorize('update', $this->server);

        $destination = trim($this->route_destination);
        $gateway = trim($this->route_gateway);

        if (! $this->isValidRemoteCidr($destination)) {
            $this->addError('route_destination', __('Enter a valid CIDR (e.g. 192.168.1.0/24).'));
            return;
        }

        if (! filter_var($gateway, FILTER_VALIDATE_IP)) {
            $this->addError('route_gateway', __('Enter a valid IP address for the gateway.'));
            return;
        }

        $networkId = (int) ($this->server->hetzner_network_id ?? 0);
        if ($networkId === 0) {
            $this->toastError(__('This server is not attached to a Hetzner private network.'));
            return;
        }

        $credential = $this->server->providerCredential;
        if (! $credential) {
            $this->toastError(__('No Hetzner credential found.'));
            return;
        }

        try {
            $hetzner = new HetznerService($credential);
            $hetzner->addNetworkRoute($networkId, $destination, $gateway);
            $this->route_destination = '';
            $this->route_gateway = '';
            $this->toastSuccess(__('Route :dest → :gw added.', ['dest' => $destination, 'gw' => $gateway]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function deleteRoute(string $destination, string $gateway): void
    {
        $this->authorize('update', $this->server);

        $networkId = (int) ($this->server->hetzner_network_id ?? 0);
        if ($networkId === 0) {
            return;
        }

        $credential = $this->server->providerCredential;
        if (! $credential) {
            return;
        }

        try {
            $hetzner = new HetznerService($credential);
            $hetzner->deleteNetworkRoute($networkId, $destination, $gateway);
            $this->toastSuccess(__('Route :dest removed.', ['dest' => $destination]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }
}
