<?php

declare(strict_types=1);

namespace App\Services\ProductionData;

use App\Enums\ServerProvider;
use App\Models\ProductionDataConnection;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseEngine;
use App\Models\ServerMetricSnapshot;
use App\Models\User;
use App\Support\Servers\InstalledStack;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Upserts a local Server from the Production REST API so Manage opens the real
 * server workspace instead of bouncing out to the remote control plane — the
 * same move ProductionSiteMaterializer makes for sites.
 *
 * This class owns the canonical mirror-stub shape; ProductionSiteMaterializer
 * delegates to it rather than keeping a second copy that can drift.
 */
final class ProductionServerMaterializer
{
    public function __construct(
        private readonly ProductionDataMirror $mirror,
    ) {}

    /**
     * Resolve a local server for the remote id: reuse an existing row when
     * present, otherwise create a mirror stub from the Production API payload.
     */
    public function open(ProductionDataConnection $connection, string $remoteServerId, User $user): Server
    {
        $remoteServerId = trim($remoteServerId);
        if ($remoteServerId === '') {
            throw new RuntimeException('Missing production server id.');
        }

        $org = $user->currentOrganization();
        if ($org === null) {
            throw new RuntimeException('Select an organization first.');
        }

        $existing = Server::query()->find($remoteServerId);
        if ($existing !== null) {
            if ((int) $existing->organization_id !== (int) $org->id) {
                throw new RuntimeException('That server id belongs to a different organization locally.');
            }

            // Only re-sync rows we created as mirrors. A real local server that
            // happens to share the id is never overwritten from the remote.
            if (data_get($existing->meta, 'production_data_mirror') === true) {
                $payload = $this->fetchPayload($connection, $remoteServerId);
                if ($payload !== []) {
                    return $this->upsert($connection, $user, $remoteServerId, $payload);
                }
            }

            return $existing;
        }

        $payload = $this->fetchPayload($connection, $remoteServerId);
        if ($payload === []) {
            throw new RuntimeException('Production API did not return that server.');
        }

        return $this->upsert($connection, $user, $remoteServerId, $payload);
    }

    /**
     * The Production API exposes no per-server show route, so the fleet list is
     * the only source. Reusing the servers index' cache key means opening Manage
     * straight off that list costs no extra round trip.
     *
     * @return array<string, mixed>
     */
    private function fetchPayload(ProductionDataConnection $connection, string $remoteServerId): array
    {
        $rows = $this->mirror->remember(
            $connection,
            'servers.fleet.v3',
            fn (ProductionApiClient $client) => $client->servers(),
        );

        $row = collect($rows)->first(
            fn (array $candidate): bool => (string) ($candidate['id'] ?? '') === $remoteServerId
        );

        return is_array($row) ? $row : [];
    }

    /**
     * Canonical mirror-stub upsert. `$payload` uses the Production *server*
     * shape (`name`, `ip_address`, `provider`, …); a caller holding a site
     * payload maps `server_name` onto `name` before calling.
     *
     * status/setup_status are pinned to ready/done rather than mirrored: the
     * stub exists so the workspace opens, and a half-provisioned status would
     * route the operator into the provisioning journey for a host that is
     * already live on the remote.
     *
     * @param  array<string, mixed>  $payload
     */
    public function upsert(
        ProductionDataConnection $connection,
        User $user,
        string $serverId,
        array $payload,
    ): Server {
        $org = $user->currentOrganization();
        if ($org === null) {
            throw new RuntimeException('Select an organization first.');
        }

        $server = Server::query()->find($serverId);

        if ($server !== null && (int) $server->organization_id !== (int) $org->id) {
            throw new RuntimeException('Production server id collides with a local server in another organization.');
        }

        if ($server === null) {
            $server = new Server;
            $server->id = $serverId;
        }

        $meta = is_array($server->meta) ? $server->meta : [];
        $meta['production_data_mirror'] = true;
        $meta['production_base_url'] = $connection->base_url;

        // Role picks the workspace sidebar profile (server_workspace.role_nav_keys)
        // and the installed stack feeds the Databases / Runtime tiles. Without
        // these a mirrored ClickHouse host renders as a generic app server —
        // "Sites" instead of "Database", and "No engine recorded".
        $role = $this->resolveServerRole($payload);
        if ($role !== null) {
            $meta['server_role'] = $role;
        }

        $stack = $this->resolveInstalledStack($payload);
        if ($stack !== []) {
            $meta[InstalledStack::META_KEY] = array_merge(
                is_array($meta[InstalledStack::META_KEY] ?? null) ? $meta[InstalledStack::META_KEY] : [],
                $stack,
            );
        }

        // Host kind decides whether this row is a machine at all. Mirroring it
        // keeps a non-VM host out of the local /servers list through the same
        // scopes the remote uses, instead of it landing as a plain "custom" VM
        // that can never be SSHed into.
        if (is_string($payload['host_kind'] ?? null) && $payload['host_kind'] !== '') {
            $meta['host_kind'] = $payload['host_kind'];
        }

        if (isset($payload['tags']) && is_array($payload['tags'])) {
            $meta['tags'] = array_values(array_filter(array_map(
                static fn ($tag): string => is_string($tag) ? trim($tag) : '',
                $payload['tags'],
            )));
        }

        $provider = $server->provider ?? ServerProvider::Custom;
        $providerValue = isset($payload['provider']) && is_string($payload['provider'])
            ? trim($payload['provider'])
            : '';
        if ($providerValue !== '') {
            $provider = ServerProvider::tryFrom($providerValue) ?? $provider;
        }

        $server->fill([
            'user_id' => $server->user_id ?: $user->id,
            'organization_id' => $org->id,
            'name' => (string) (($payload['name'] ?? $server->name) ?: 'production-server'),
            'ip_address' => isset($payload['ip_address']) && is_string($payload['ip_address']) && $payload['ip_address'] !== ''
                ? $payload['ip_address']
                : $server->ip_address,
            // Address only. The network FKs are intentionally not mirrored (see
            // ServerIndexAssembler), so this host shows its real private
            // address without being matched into a local network's peer list.
            'private_ip_address' => isset($payload['private_ip_address']) && is_string($payload['private_ip_address']) && $payload['private_ip_address'] !== ''
                ? $payload['private_ip_address']
                : $server->private_ip_address,
            'provider' => $provider,
            'status' => Server::STATUS_READY,
            'setup_status' => Server::SETUP_STATUS_DONE,
            'ssh_port' => $server->ssh_port ?: 22,
            'ssh_user' => $server->ssh_user ?: 'dply',
            // Reachability is the remote's verdict — this host is never probed
            // from here, so without these the mirror always reads "No health
            // check yet" for a machine the control plane checked minutes ago.
            'health_status' => is_string($payload['health_status'] ?? null) && $payload['health_status'] !== ''
                ? $payload['health_status']
                : $server->health_status,
            'last_health_check_at' => $this->parseTimestamp($payload['health_checked_at'] ?? null)
                ?? $server->last_health_check_at,
            'meta' => $meta,
        ]);
        $server->save();

        $this->syncServices($server, $payload);
        $this->syncDatabases($server, $payload);

        return $server;
    }

    /**
     * Mirror the metrics agent: its state onto `meta`, its samples into
     * `server_metric_snapshots`.
     *
     * Without this the Metrics workspace reads a host with a live agent as
     * un-provisioned — `monitoring_python_installed` is absent, so the page
     * offers to install an agent that has been running for months, and the
     * usage/history panels have no rows to draw. Everything the page needs is
     * derived from these two sources (ServerMetricsGuestPushVerifier is pure
     * meta arithmetic), so mirroring them is enough to render it for real.
     *
     * `$metrics` is decoded JSON off the wire, so it is typed as loosely as it
     * actually arrives — json_decode turns a numeric-looking key into an int,
     * and a remote on an older build can send anything at all.
     *
     * @param  array{monitoring?: array<array-key, mixed>, thresholds?: array<string, float>|null, snapshots?: list<mixed>}  $metrics
     */
    public function syncMonitoring(Server $server, array $metrics): Server
    {
        if (data_get($server->meta, 'production_data_mirror') !== true) {
            return $server;
        }

        $meta = is_array($server->meta) ? $server->meta : [];

        $monitoring = is_array($metrics['monitoring'] ?? null) ? $metrics['monitoring'] : [];
        foreach ($monitoring as $key => $value) {
            if (! is_string($key) || ! str_starts_with($key, 'monitoring_')) {
                continue;
            }
            $meta[$key] = $value;
        }

        // The remote never sends the guest push token hash — only whether one
        // exists. The verifier asks `! empty(...)` of the hash, so a marker
        // value answers the only question anything here asks of it.
        if (array_key_exists('monitoring_guest_push_token_present', $monitoring)) {
            if ($monitoring['monitoring_guest_push_token_present'] === true) {
                $meta['monitoring_guest_push_token_hash'] = 'mirrored';
            } else {
                unset($meta['monitoring_guest_push_token_hash']);
            }
            unset($meta['monitoring_guest_push_token_present']);
        }

        // Probes and installs run on the remote; a pending flag mirrored here
        // would never be cleared locally (nothing local finishes it), so the
        // next pull is what moves it. Keep the value, drop the local staleness
        // bookkeeping that only makes sense next to a local job.
        unset($meta['monitoring_probe_pending_at']);

        if (is_array($metrics['thresholds'] ?? null) && $metrics['thresholds'] !== []) {
            $meta['metric_thresholds'] = $metrics['thresholds'];
        }

        $server->forceFill(['meta' => $meta])->saveQuietly();

        $this->syncMetricSnapshots($server, is_array($metrics['snapshots'] ?? null) ? $metrics['snapshots'] : []);

        return $server->fresh() ?? $server;
    }

    /**
     * Upsert mirrored samples keyed on captured_at, so repeated pulls of an
     * overlapping window neither duplicate rows nor lose history the remote has
     * already aged out of its own response window.
     *
     * @param  list<mixed>  $snapshots  Decoded JSON rows — shape is checked, not assumed.
     */
    private function syncMetricSnapshots(Server $server, array $snapshots): void
    {
        foreach ($snapshots as $snapshot) {
            if (! is_array($snapshot)) {
                continue;
            }

            $capturedAt = $this->parseTimestamp($snapshot['captured_at'] ?? null);
            if ($capturedAt === null) {
                continue;
            }

            ServerMetricSnapshot::query()->updateOrCreate(
                ['server_id' => $server->id, 'captured_at' => $capturedAt],
                ['payload' => is_array($snapshot['payload'] ?? null) ? $snapshot['payload'] : []],
            );
        }
    }

    /**
     * Mirror the host's user databases. The Databases tile counts local
     * ServerDatabase rows, so without these a live host with databases reads 0.
     * Names are all the fleet payload carries — credentials stay on the remote
     * and are never mirrored.
     *
     * @param  array<string, mixed>  $payload
     */
    private function syncDatabases(Server $server, array $payload): void
    {
        if (data_get($server->meta, 'production_data_mirror') !== true) {
            return;
        }

        // Absent key = the remote didn't send the relation (older API); leave
        // whatever is there. An explicit empty list does mean "none".
        if (! array_key_exists('databases', $payload) || ! is_array($payload['databases'])) {
            return;
        }

        $names = [];
        foreach ($payload['databases'] as $database) {
            $name = is_array($database)
                ? (is_string($database['name'] ?? null) ? trim($database['name']) : '')
                : (is_string($database) ? trim($database) : '');
            if ($name !== '') {
                $names[] = $name;
            }
        }

        foreach ($names as $name) {
            // firstOrCreate, not updateOrCreate: credentials are NOT NULL, and
            // re-writing them on every sync would blank an existing row. The
            // stub carries empty credentials on purpose — this mirror is never
            // used to connect to the database, only to report that it exists.
            ServerDatabase::query()->firstOrCreate(
                ['server_id' => $server->id, 'name' => $name],
                ['username' => '', 'password' => ''],
            );
        }

        // Drop mirrored rows the remote no longer reports, so a dropped
        // database stops being counted here.
        ServerDatabase::query()
            ->where('server_id', $server->id)
            ->when($names !== [], fn ($query) => $query->whereNotIn('name', $names))
            ->delete();
    }

    private function parseTimestamp(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Mirror the remote's installed engines into the local engine/cache tables.
     *
     * Without these rows the workspace treats a live production database host
     * as bare metal — the setup checklist offers "Install ClickHouse" against a
     * box that is already running it. Scoped to mirror stubs so a real local
     * server's engine inventory is never rewritten from remote data.
     *
     * @param  array<string, mixed>  $payload
     */
    private function syncServices(Server $server, array $payload): void
    {
        if (data_get($server->meta, 'production_data_mirror') !== true) {
            return;
        }

        $services = is_array($payload['services'] ?? null) ? $payload['services'] : [];
        if ($services === []) {
            return;
        }

        foreach ($services as $service) {
            if (! is_array($service)) {
                continue;
            }

            $engine = is_string($service['engine'] ?? null) ? trim($service['engine']) : '';
            if ($engine === '') {
                continue;
            }

            $version = is_string($service['version'] ?? null) && trim($service['version']) !== ''
                ? trim($service['version'])
                : null;
            // The services list often carries a null version while the
            // reconciled stack snapshot has the real one (that snapshot is what
            // the remote's own tile reads), so fall back to it for the default
            // database engine rather than rendering a bare "ClickHouse".
            if ($version === null
                && (string) ($service['kind'] ?? '') === 'database'
                && ($service['is_default'] ?? false) === true
            ) {
                $stack = is_array($payload['installed_stack'] ?? null) ? $payload['installed_stack'] : [];
                $version = is_string($stack['database_version'] ?? null) && trim($stack['database_version']) !== ''
                    ? trim($stack['database_version'])
                    : null;
            }
            $status = is_string($service['status'] ?? null) && trim($service['status']) !== ''
                ? trim($service['status'])
                : null;

            match ((string) ($service['kind'] ?? '')) {
                'database' => ServerDatabaseEngine::query()->updateOrCreate(
                    ['server_id' => $server->id, 'engine' => $engine],
                    array_filter([
                        'version' => $version,
                        'status' => $status,
                        'is_default' => (bool) ($service['is_default'] ?? false),
                    ], static fn ($value): bool => $value !== null),
                ),
                'cache' => ServerCacheService::query()->updateOrCreate(
                    ['server_id' => $server->id, 'engine' => $engine],
                    array_filter([
                        'version' => $version,
                        'status' => $status,
                    ], static fn ($value): bool => $value !== null),
                ),
                default => null,
            };
        }
    }

    /**
     * `server_role` ships in the fleet payload (ServerIndexAssembler). Older
     * remotes predate that field, so fall back to inferring a dedicated
     * database/cache host from its services: one engine, marked default, and
     * no sites on the box. An app server that merely has MySQL installed keeps
     * sites, so it stays on the generic profile.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveServerRole(array $payload): ?string
    {
        $role = $payload['server_role'] ?? null;
        if (is_string($role) && trim($role) !== '') {
            return trim($role);
        }

        if ((int) ($payload['sites_count'] ?? 0) > 0) {
            return null;
        }

        $services = is_array($payload['services'] ?? null) ? $payload['services'] : [];
        $defaults = array_values(array_filter(
            $services,
            static fn ($service): bool => is_array($service) && ($service['is_default'] ?? false) === true
        ));

        if (count($defaults) !== 1) {
            return null;
        }

        $service = $defaults[0];
        $engine = is_string($service['engine'] ?? null) ? trim($service['engine']) : '';

        return match ((string) ($service['kind'] ?? '')) {
            // Cache hosts get an engine-named profile (redis / valkey); database
            // hosts share one 'database' profile regardless of engine.
            'cache' => config()->has('server_workspace.role_nav_keys.'.$engine) ? $engine : null,
            'database' => 'database',
            default => null,
        };
    }

    /**
     * Prefer the remote's reconciled snapshot; otherwise rebuild what the tiles
     * need from the services list, which carries engine + version per service.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function resolveInstalledStack(array $payload): array
    {
        $stack = $payload['installed_stack'] ?? null;
        if (is_array($stack) && array_filter($stack, static fn ($v): bool => $v !== null) !== []) {
            return $stack;
        }

        $derived = [];
        $services = is_array($payload['services'] ?? null) ? $payload['services'] : [];
        foreach ($services as $service) {
            if (! is_array($service)) {
                continue;
            }

            $engine = is_string($service['engine'] ?? null) ? trim($service['engine']) : '';
            if ($engine === '') {
                continue;
            }

            $version = is_string($service['version'] ?? null) && trim($service['version']) !== ''
                ? trim($service['version'])
                : null;

            // First default wins; otherwise the first of its kind.
            if ((string) ($service['kind'] ?? '') === 'database' && ! isset($derived['database'])) {
                $derived['database'] = $engine;
                $derived['database_version'] = $version;
            }
            if ((string) ($service['kind'] ?? '') === 'cache' && ! isset($derived['cache_service'])) {
                $derived['cache_service'] = $engine;
            }
        }

        return $derived;
    }
}
