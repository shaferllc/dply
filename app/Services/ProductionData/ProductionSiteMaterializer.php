<?php

declare(strict_types=1);

namespace App\Services\ProductionData;

use App\Enums\SiteType;
use App\Models\ProductionDataConnection;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Upserts a local Site (+ Server) from the Production REST API so Manage can
 * open the real site workspace while still operating under the Production
 * mirror connection.
 */
final class ProductionSiteMaterializer
{
    public function __construct(
        private readonly ProductionDataMirror $mirror,
        private readonly ProductionServerMaterializer $servers,
    ) {}

    /**
     * Resolve a local site for the remote id: reuse an existing row when present,
     * otherwise create mirror stubs from the Production API payload.
     */
    public function open(ProductionDataConnection $connection, string $remoteSiteId, User $user): Site
    {
        $remoteSiteId = trim($remoteSiteId);
        if ($remoteSiteId === '') {
            throw new RuntimeException('Missing production site id.');
        }

        $org = $user->currentOrganization();
        if ($org === null) {
            throw new RuntimeException('Select an organization first.');
        }

        $existing = Site::query()->with('server')->find($remoteSiteId);
        if ($existing !== null && $existing->server !== null) {
            if ((int) $existing->organization_id !== (int) $org->id
                && (int) $existing->server->organization_id !== (int) $org->id) {
                throw new RuntimeException('That site id belongs to a different organization locally.');
            }

            if (data_get($existing->meta, 'production_data_mirror') === true) {
                $this->refreshMirrorSite($connection, $existing);
            }

            return $existing->fresh(['server', 'domains']) ?? $existing;
        }

        $payload = $this->fetchPayload($connection, $remoteSiteId);

        return $this->materialize($connection, $payload, $user);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPayload(ProductionDataConnection $connection, string $remoteSiteId): array
    {
        $show = $this->mirror->withClient(
            $connection,
            fn (ProductionApiClient $client) => $client->site($remoteSiteId),
        );

        if ($show === [] || ! isset($show['id'])) {
            throw new RuntimeException('Production API did not return that site.');
        }

        $listRows = $this->mirror->remember(
            $connection,
            'sites.fleet.v2',
            fn (ProductionApiClient $client) => $client->sites(),
        );
        $listRow = collect($listRows)->first(
            fn (array $row): bool => (string) ($row['id'] ?? '') === $remoteSiteId
        );

        return is_array($listRow) ? array_merge($listRow, $show) : $show;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function materialize(ProductionDataConnection $connection, array $payload, User $user): Site
    {
        $org = $user->currentOrganization();
        if ($org === null) {
            throw new RuntimeException('Select an organization first.');
        }

        $siteId = (string) ($payload['id'] ?? '');
        $serverId = (string) ($payload['server_id'] ?? '');
        if ($siteId === '' || $serverId === '') {
            throw new RuntimeException('Production site payload is missing id or server_id.');
        }

        $server = $this->upsertServer($connection, $payload, $user, $serverId);
        $site = $this->upsertSite($connection, $payload, $user, $server, $siteId);
        $this->syncPrimaryDomain($site, $payload);

        return $site->fresh(['server', 'domains']) ?? $site;
    }

    private function refreshMirrorSite(ProductionDataConnection $connection, Site $site): void
    {
        try {
            $payload = $this->fetchPayload($connection, (string) $site->id);
        } catch (ProductionApiException) {
            return;
        }

        if ($site->server !== null) {
            $this->upsertServer($connection, $payload, $site->user ?? auth()->user(), (string) $site->server_id);
        }

        $user = $site->user ?? auth()->user();
        if ($user instanceof User && $site->server !== null) {
            $this->upsertSite($connection, $payload, $user, $site->server, (string) $site->id);
            $this->syncPrimaryDomain($site->fresh() ?? $site, $payload);
        }
    }

    /**
     * Delegates to ProductionServerMaterializer so the mirror-stub shape lives
     * in one place. A site payload names the host `server_name`, so map it onto
     * the server payload's `name` before handing it over.
     *
     * @param  array<string, mixed>  $payload
     */
    private function upsertServer(
        ProductionDataConnection $connection,
        array $payload,
        User $user,
        string $serverId,
    ): Server {
        return $this->servers->upsert($connection, $user, $serverId, array_filter([
            'name' => $payload['server_name'] ?? null,
        ], static fn ($value): bool => $value !== null));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertSite(
        ProductionDataConnection $connection,
        array $payload,
        User $user,
        Server $server,
        string $siteId,
    ): Site {
        $site = Site::query()->find($siteId);

        if ($site !== null && (int) $site->server_id !== (int) $server->id
            && $site->server_id !== null) {
            throw new RuntimeException('Production site id collides with a local site on another server.');
        }

        if ($site === null) {
            $site = new Site;
            $site->id = $siteId;
        }

        $typeValue = (string) ($payload['type'] ?? SiteType::Php->value);
        try {
            $type = SiteType::from($typeValue);
        } catch (\ValueError) {
            $type = SiteType::Php;
        }

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['production_data_mirror'] = true;
        $meta['production_base_url'] = $connection->base_url;

        $lastDeploy = isset($payload['last_deploy_at']) && is_string($payload['last_deploy_at']) && $payload['last_deploy_at'] !== ''
            ? Carbon::parse($payload['last_deploy_at'])
            : $site->last_deploy_at;

        $name = (string) (($payload['name'] ?? $site->name) ?: 'site');
        $slug = (string) (($payload['slug'] ?? $site->slug) ?: (Str::slug($name) ?: 'site'));

        $site->fill([
            'server_id' => $server->id,
            'user_id' => $site->user_id ?: $user->id,
            'organization_id' => $server->organization_id,
            'name' => $name,
            'slug' => $slug,
            'type' => $type,
            'document_root' => $payload['document_root'] ?? $site->document_root,
            'runtime' => $payload['runtime'] ?? $site->runtime,
            'runtime_version' => $payload['runtime_version'] ?? $site->runtime_version,
            'status' => (string) ($payload['status'] ?? $site->status ?: Site::STATUS_NGINX_ACTIVE),
            'ssl_status' => $payload['ssl_status'] ?? $site->ssl_status,
            'git_repository_url' => $payload['git_repository_url'] ?? $site->git_repository_url,
            'git_branch' => $payload['git_branch'] ?? $site->git_branch,
            'deploy_strategy' => $payload['deploy_strategy'] ?? $site->deploy_strategy ?? 'simple',
            'last_deploy_at' => $lastDeploy,
            'meta' => $meta,
        ]);
        $site->save();

        return $site;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncPrimaryDomain(Site $site, array $payload): void
    {
        $hostname = isset($payload['primary_hostname']) && is_string($payload['primary_hostname'])
            ? trim($payload['primary_hostname'])
            : '';
        if ($hostname === '') {
            return;
        }

        $domain = SiteDomain::query()
            ->where('site_id', $site->id)
            ->where('hostname', $hostname)
            ->first();

        if ($domain === null) {
            SiteDomain::query()->create([
                'site_id' => $site->id,
                'hostname' => $hostname,
                'is_primary' => true,
                'www_redirect' => false,
            ]);

            return;
        }

        if (! $domain->is_primary) {
            SiteDomain::query()->where('site_id', $site->id)->update(['is_primary' => false]);
            $domain->forceFill(['is_primary' => true])->save();
        }
    }
}
