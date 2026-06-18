<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Forge\ForgeInventorySyncTest;

use App\Modules\Imports\Services\ImportDriver;

final class FakeForgeDriver implements ImportDriver
{
    /**
     * @param  list<array<string, mixed>>  $servers
     * @param  array<int, list<array<string, mixed>>>  $sitesByServer
     * @param  array<int, array<string, mixed>>  $serverDetail
     */
    public function __construct(protected array $servers = [], protected array $sitesByServer = [], protected array $serverDetail = []) {}

    public function source(): string
    {
        return 'forge';
    }

    public function validateConnection(): void {}

    public function listServers(): array
    {
        return $this->servers;
    }

    public function fetchServerDetail(int $sourceServerId): array
    {
        return $this->serverDetail[$sourceServerId]
            ?? throw new \RuntimeException("No detail seeded for {$sourceServerId}");
    }

    public function listSites(int $sourceServerId): array
    {
        return $this->sitesByServer[$sourceServerId] ?? [];
    }

    public function fetchSiteDetail(int $sourceServerId, int $sourceSiteId): array
    {
        throw new \RuntimeException('not used in sync tests');
    }

    public function pushSshKey(int $sourceServerId, string $label, string $publicKey): int
    {
        return 0;
    }

    public function revokeSshKey(int $sourceServerId, int $sourceKeyId): void {}

    public function fetchEnv(int $sourceServerId, int $sourceSiteId): string
    {
        return '';
    }

    public function listSiteCrons(int $sourceServerId, int $sourceSiteId): array
    {
        return [];
    }

    public function listDaemons(int $sourceServerId, int $sourceSiteId): array
    {
        return [];
    }

    public function listSiteDatabases(int $sourceServerId, int $sourceSiteId): array
    {
        return [];
    }

    public function fetchSiteCertificate(int $sourceServerId, int $sourceSiteId): ?array
    {
        return null;
    }

    public function enableSiteMaintenance(int $sourceServerId, int $sourceSiteId): void {}

    public function disableSiteMaintenance(int $sourceServerId, int $sourceSiteId): void {}

    public function listSiteWebhooks(int $sourceServerId, int $sourceSiteId): array
    {
        return [];
    }

    public function deleteSiteWebhook(int $sourceServerId, int $sourceSiteId, int $webhookId): void {}
}
