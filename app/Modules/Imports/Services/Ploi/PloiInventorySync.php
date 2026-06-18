<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services\Ploi;

use App\Models\PloiServer;
use App\Models\PloiSite;
use App\Models\ProviderCredential;
use App\Modules\Imports\Services\ImportDriver;
use App\Modules\Imports\Services\SyncResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pulls the user's Ploi fleet into ploi_servers / ploi_sites rows. Idempotent;
 * safe to re-run. Source rows that disappear from Ploi are marked
 * removed_from_source rather than deleted, preserving audit trail and any
 * in-progress migrations holding a frozen snapshot against them.
 *
 * Pre-migration callers should pass syncSites=true and a single $onlyServerId
 * to bound the cost to just the server being migrated (per Q15 design — the
 * blocking pre-migration re-sync).
 */
class PloiInventorySync
{
    public function __construct(protected ?ImportDriver $driver = null) {}

    public function syncAll(ProviderCredential $credential): SyncResult
    {
        if ($credential->provider !== 'ploi') {
            throw new RuntimeException(
                sprintf('Expected provider=ploi for sync, got %s', $credential->provider)
            );
        }

        $driver = $this->driver ?? PloiImportDriver::for($credential);
        $now = Carbon::now();

        $sourceServers = $driver->listServers();
        $seenServerSourceIds = [];
        $serversTouched = 0;
        $sitesTouched = 0;

        foreach ($sourceServers as $row) {
            $server = $this->upsertServer($credential, $row, $now);
            $seenServerSourceIds[] = $row['id'];
            $serversTouched++;

            $sourceSites = $driver->listSites($row['id']);
            $seenSiteSourceIds = [];
            foreach ($sourceSites as $siteRow) {
                $this->upsertSite($server, $siteRow);
                $seenSiteSourceIds[] = $siteRow['id'];
                $sitesTouched++;
            }
            $this->markMissingSitesRemoved($server, $seenSiteSourceIds);
        }
        $this->markMissingServersRemoved($credential, $seenServerSourceIds);

        return new SyncResult(
            serversSeen: $serversTouched,
            sitesSeen: $sitesTouched,
            syncedAt: $now,
        );
    }

    public function syncOneServer(ProviderCredential $credential, int $sourceServerId): SyncResult
    {
        if ($credential->provider !== 'ploi') {
            throw new RuntimeException(
                sprintf('Expected provider=ploi for sync, got %s', $credential->provider)
            );
        }

        $driver = $this->driver ?? PloiImportDriver::for($credential);
        $now = Carbon::now();

        $serverRow = $driver->fetchServerDetail($sourceServerId);
        $server = $this->upsertServer($credential, $serverRow, $now);

        $sourceSites = $driver->listSites($sourceServerId);
        $seenSiteSourceIds = [];
        foreach ($sourceSites as $siteRow) {
            $this->upsertSite($server, $siteRow);
            $seenSiteSourceIds[] = $siteRow['id'];
        }
        $this->markMissingSitesRemoved($server, $seenSiteSourceIds);

        return new SyncResult(
            serversSeen: 1,
            sitesSeen: count($sourceSites),
            syncedAt: $now,
        );
    }

    /**
     * @param  array{
     *     id: int, name: string, ip_address: ?string, provider_label: ?string,
     *     server_type: ?string, php_versions: list<string>, status: ?string,
     *     raw: array<string, mixed>,
     * }  $row
     */
    protected function upsertServer(ProviderCredential $credential, array $row, Carbon $now): PloiServer
    {
        return DB::transaction(function () use ($credential, $row, $now): PloiServer {
            $server = PloiServer::query()
                ->where('provider_credential_id', $credential->id)
                ->where('source_id', $row['id'])
                ->lockForUpdate()
                ->first();

            $attributes = [
                'provider_credential_id' => $credential->id,
                'source_id' => $row['id'],
                'name' => $row['name'],
                'ip_address' => $row['ip_address'],
                'provider_label' => $row['provider_label'],
                'server_type' => $row['server_type'],
                'php_versions' => $row['php_versions'],
                'status' => $row['status'],
                'last_synced_at' => $now,
                'removed_from_source' => false,
                'source_snapshot' => $row['raw'],
            ];

            if ($server === null) {
                return PloiServer::create($attributes);
            }
            $server->fill($attributes)->save();

            return $server;
        });
    }

    /**
     * @param  array{
     *     id: int, domain: string, site_type: string, php_version: ?string,
     *     repository_url: ?string, repository_branch: ?string, web_directory: ?string,
     *     status: ?string, raw: array<string, mixed>,
     * }  $row
     */
    protected function upsertSite(PloiServer $server, array $row): PloiSite
    {
        return DB::transaction(function () use ($server, $row): PloiSite {
            $site = PloiSite::query()
                ->where('ploi_server_id', $server->id)
                ->where('source_id', $row['id'])
                ->lockForUpdate()
                ->first();

            $attributes = [
                'ploi_server_id' => $server->id,
                'source_id' => $row['id'],
                'domain' => $row['domain'],
                'site_type' => $row['site_type'],
                'php_version' => $row['php_version'],
                'repository_url' => $row['repository_url'],
                'repository_branch' => $row['repository_branch'],
                'web_directory' => $row['web_directory'],
                'status' => $row['status'],
                'removed_from_source' => false,
                'source_snapshot' => $row['raw'],
            ];

            if ($site === null) {
                return PloiSite::create($attributes);
            }
            $site->fill($attributes)->save();

            return $site;
        });
    }

    /**
     * @param  array<string, mixed> $seenSourceIds
     */
    protected function markMissingServersRemoved(ProviderCredential $credential, array $seenSourceIds): void
    {
        $query = PloiServer::query()
            ->where('provider_credential_id', $credential->id)
            ->where('removed_from_source', false);

        if ($seenSourceIds !== []) {
            $query->whereNotIn('source_id', $seenSourceIds);
        }

        // Snapshot the about-to-be-removed servers before flipping the flag so we can
        // cascade-mark their sites. The parent disappearing from Ploi implies every
        // site under it is also gone, even though we never visited them in this pull.
        $vanishedServerIds = $query->clone()->pluck('id')->all();
        $query->update(['removed_from_source' => true]);

        if ($vanishedServerIds !== []) {
            PloiSite::query()
                ->whereIn('ploi_server_id', $vanishedServerIds)
                ->where('removed_from_source', false)
                ->update(['removed_from_source' => true]);
        }
    }

    /**
     * @param  array<string, mixed> $seenSourceIds
     */
    protected function markMissingSitesRemoved(PloiServer $server, array $seenSourceIds): void
    {
        $query = PloiSite::query()
            ->where('ploi_server_id', $server->id)
            ->where('removed_from_source', false);

        if ($seenSourceIds !== []) {
            $query->whereNotIn('source_id', $seenSourceIds);
        }
        $query->update(['removed_from_source' => true]);
    }
}
