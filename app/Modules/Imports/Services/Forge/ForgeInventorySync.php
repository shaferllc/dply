<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services\Forge;

use App\Models\ForgeServer;
use App\Models\ForgeSite;
use App\Models\ProviderCredential;
use App\Modules\Imports\Services\ImportDriver;
use App\Modules\Imports\Services\SyncResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pulls a user's Forge fleet into forge_servers / forge_sites. Same idempotent
 * upsert + cascade-mark semantics as PloiInventorySync (Q15 source-deletion
 * = removed_from_source).
 */
class ForgeInventorySync
{
    public function __construct(protected ?ImportDriver $driver = null) {}

    public function syncAll(ProviderCredential $credential): SyncResult
    {
        $this->assertForge($credential);

        $driver = $this->driver ?? ForgeImportDriver::for($credential);
        $now = Carbon::now();

        $sourceServers = $driver->listServers();
        $seenServers = [];
        $serversTouched = 0;
        $sitesTouched = 0;

        foreach ($sourceServers as $row) {
            $server = $this->upsertServer($credential, $row, $now);
            $seenServers[] = $row['id'];
            $serversTouched++;

            $seenSites = [];
            foreach ($driver->listSites($row['id']) as $siteRow) {
                $this->upsertSite($server, $siteRow);
                $seenSites[] = $siteRow['id'];
                $sitesTouched++;
            }
            $this->markMissingSitesRemoved($server, $seenSites);
        }
        $this->markMissingServersRemoved($credential, $seenServers);

        return new SyncResult(
            serversSeen: $serversTouched,
            sitesSeen: $sitesTouched,
            syncedAt: $now,
        );
    }

    public function syncOneServer(ProviderCredential $credential, int $sourceServerId): SyncResult
    {
        $this->assertForge($credential);

        $driver = $this->driver ?? ForgeImportDriver::for($credential);
        $now = Carbon::now();

        $row = $driver->fetchServerDetail($sourceServerId);
        $server = $this->upsertServer($credential, $row, $now);

        $sourceSites = $driver->listSites($sourceServerId);
        $seenSites = [];
        foreach ($sourceSites as $siteRow) {
            $this->upsertSite($server, $siteRow);
            $seenSites[] = $siteRow['id'];
        }
        $this->markMissingSitesRemoved($server, $seenSites);

        return new SyncResult(
            serversSeen: 1,
            sitesSeen: count($sourceSites),
            syncedAt: $now,
        );
    }

    protected function assertForge(ProviderCredential $credential): void
    {
        if ($credential->provider !== 'forge') {
            throw new RuntimeException(
                sprintf('Expected provider=forge for sync, got %s', $credential->provider)
            );
        }
    }

    /**
     * @param  array{
     *     id: int, name: string, ip_address: ?string, provider_label: ?string,
     *     server_type: ?string, php_versions: list<string>, status: ?string,
     *     raw: array<string, mixed>,
     * }  $row
     */
    protected function upsertServer(ProviderCredential $credential, array $row, Carbon $now): ForgeServer
    {
        return DB::transaction(function () use ($credential, $row, $now): ForgeServer {
            $server = ForgeServer::query()
                ->where('provider_credential_id', $credential->id)
                ->where('source_id', $row['id'])
                ->lockForUpdate()
                ->first();

            $attrs = [
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
                return ForgeServer::create($attrs);
            }
            $server->fill($attrs)->save();

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
    protected function upsertSite(ForgeServer $server, array $row): ForgeSite
    {
        return DB::transaction(function () use ($server, $row): ForgeSite {
            $site = ForgeSite::query()
                ->where('forge_server_id', $server->id)
                ->where('source_id', $row['id'])
                ->lockForUpdate()
                ->first();

            $attrs = [
                'forge_server_id' => $server->id,
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
                return ForgeSite::create($attrs);
            }
            $site->fill($attrs)->save();

            return $site;
        });
    }

    /**
     * @param  array<string, mixed> $seen
     */
    protected function markMissingServersRemoved(ProviderCredential $credential, array $seen): void
    {
        $query = ForgeServer::query()
            ->where('provider_credential_id', $credential->id)
            ->where('removed_from_source', false);
        if ($seen !== []) {
            $query->whereNotIn('source_id', $seen);
        }
        $vanished = $query->clone()->pluck('id')->all();
        $query->update(['removed_from_source' => true]);

        if ($vanished !== []) {
            ForgeSite::query()
                ->whereIn('forge_server_id', $vanished)
                ->where('removed_from_source', false)
                ->update(['removed_from_source' => true]);
        }
    }

    /**
     * @param  array<string, mixed> $seen
     */
    protected function markMissingSitesRemoved(ForgeServer $server, array $seen): void
    {
        $query = ForgeSite::query()
            ->where('forge_server_id', $server->id)
            ->where('removed_from_source', false);
        if ($seen !== []) {
            $query->whereNotIn('source_id', $seen);
        }
        $query->update(['removed_from_source' => true]);
    }
}
