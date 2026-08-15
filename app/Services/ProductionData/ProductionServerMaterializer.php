<?php

declare(strict_types=1);

namespace App\Services\ProductionData;

use App\Enums\ServerProvider;
use App\Models\ProductionDataConnection;
use App\Models\Server;
use App\Models\User;
use RuntimeException;

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
            fn ($candidate): bool => is_array($candidate)
                && (string) ($candidate['id'] ?? '') === $remoteServerId
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
            'provider' => $provider,
            'status' => Server::STATUS_READY,
            'setup_status' => Server::SETUP_STATUS_DONE,
            'ssh_port' => $server->ssh_port ?: 22,
            'ssh_user' => $server->ssh_user ?: 'dply',
            'meta' => $meta,
        ]);
        $server->save();

        return $server;
    }
}
