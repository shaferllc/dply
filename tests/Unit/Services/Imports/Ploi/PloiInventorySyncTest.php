<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Ploi\PloiInventorySyncTest;
use App\Models\PloiServer;
use App\Models\PloiSite;
use App\Models\ProviderCredential;
use App\Services\Imports\ImportDriver;
use App\Services\Imports\Ploi\PloiInventorySync;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function credential(): ProviderCredential
{
    return ProviderCredential::factory()->create([
        'provider' => 'ploi',
        'credentials' => ['api_token' => 'ploi_test_token'],
    ]);
}
test('sync all inserts new servers and sites', function () {
    $driver = new FakeImportDriver(
        servers: [
            fakeServer(42, 'prod-web-01'),
            fakeServer(43, 'prod-web-02'),
        ],
        sitesByServer: [
            42 => [
                fakeSite(100, 'app.example.com', 'laravel'),
                fakeSite(101, 'static.example.com', 'static'),
            ],
            43 => [
                fakeSite(200, 'api.example.com', 'php'),
            ],
        ],
    );

    $sync = new PloiInventorySync($driver);
    $result = $sync->syncAll(credential());

    expect($result->serversSeen)->toBe(2);
    expect($result->sitesSeen)->toBe(3);
    $this->assertDatabaseCount('ploi_servers', 2);
    $this->assertDatabaseCount('ploi_sites', 3);
    $this->assertDatabaseHas('ploi_servers', ['source_id' => 42, 'name' => 'prod-web-01']);
    $this->assertDatabaseHas('ploi_sites', ['source_id' => 100, 'domain' => 'app.example.com', 'site_type' => 'laravel']);
});
test('sync all updates existing rows and sets last synced at', function () {
    $credential = credential();
    $existing = PloiServer::create([
        'provider_credential_id' => $credential->id,
        'source_id' => 42,
        'name' => 'old-name',
        'ip_address' => null,
        'provider_label' => null,
        'server_type' => null,
        'php_versions' => [],
        'status' => null,
        'last_synced_at' => null,
        'removed_from_source' => false,
        'source_snapshot' => null,
    ]);

    $driver = new FakeImportDriver(
        servers: [fakeServer(42, 'new-name')],
        sitesByServer: [42 => []],
    );

    (new PloiInventorySync($driver))->syncAll($credential);

    $this->assertDatabaseCount('ploi_servers', 1);
    $reloaded = PloiServer::find($existing->id);
    expect($reloaded->name)->toBe('new-name');
    expect($reloaded->last_synced_at)->not->toBeNull();
});
test('sync all marks missing servers and sites removed', function () {
    $credential = credential();

    // Seed: two servers, one site each — to be reduced to one server, no sites on the survivor.
    $driver = new FakeImportDriver(
        servers: [fakeServer(42, 'a'), fakeServer(43, 'b')],
        sitesByServer: [42 => [fakeSite(100, 'x.com', 'laravel')], 43 => [fakeSite(200, 'y.com', 'php')]],
    );
    (new PloiInventorySync($driver))->syncAll($credential);
    $this->assertDatabaseCount('ploi_servers', 2);
    $this->assertDatabaseCount('ploi_sites', 2);

    // Second pull: only server 42 remains, and it has no sites.
    $driverAfter = new FakeImportDriver(
        servers: [fakeServer(42, 'a')],
        sitesByServer: [42 => []],
    );
    (new PloiInventorySync($driverAfter))->syncAll($credential);

    $this->assertDatabaseHas('ploi_servers', ['source_id' => 42, 'removed_from_source' => false]);
    $this->assertDatabaseHas('ploi_servers', ['source_id' => 43, 'removed_from_source' => true]);
    $this->assertDatabaseHas('ploi_sites', ['source_id' => 100, 'removed_from_source' => true]);
    $this->assertDatabaseHas('ploi_sites', ['source_id' => 200, 'removed_from_source' => true]);
});
test('sync one server scopes removals to that server', function () {
    $credential = credential();

    // Seed two servers via full sync.
    (new PloiInventorySync(new FakeImportDriver(
        servers: [fakeServer(42, 'a'), fakeServer(43, 'b')],
        sitesByServer: [42 => [fakeSite(100, 'x.com', 'laravel')], 43 => [fakeSite(200, 'y.com', 'php')]],
    )))->syncAll($credential);

    // Pre-migration re-sync of just server 42: site 100 should remain, server 43 must stay alive.
    $driver = new FakeImportDriver(
        serverDetail: [42 => fakeServer(42, 'a-renamed')],
        sitesByServer: [42 => [fakeSite(100, 'x.com', 'laravel')]],
    );
    $result = (new PloiInventorySync($driver))->syncOneServer($credential, 42);

    expect($result->serversSeen)->toBe(1);
    $this->assertDatabaseHas('ploi_servers', ['source_id' => 42, 'name' => 'a-renamed', 'removed_from_source' => false]);
    $this->assertDatabaseHas('ploi_servers', ['source_id' => 43, 'name' => 'b', 'removed_from_source' => false]);
    $this->assertDatabaseHas('ploi_sites', ['source_id' => 200, 'removed_from_source' => false]);
});
test('sync rejects non ploi credential', function () {
    $credential = ProviderCredential::factory()->create([
        'provider' => 'digitalocean',
    ]);

    $this->expectException(\RuntimeException::class);
    (new PloiInventorySync(new FakeImportDriver()))->syncAll($credential);
});
/**
 * @return array{
 *     id: int, name: string, ip_address: ?string, provider_label: ?string,
 *     server_type: ?string, php_versions: list<string>, status: ?string,
 *     raw: array<string, mixed>,
 * }
 */
function fakeServer(int $id, string $name): array
{
    return [
        'id' => $id,
        'name' => $name,
        'ip_address' => '203.0.113.'.($id % 200),
        'provider_label' => 'digital-ocean',
        'server_type' => 's-2vcpu-4gb',
        'php_versions' => ['8.3'],
        'status' => 'active',
        'raw' => ['id' => $id, 'name' => $name],
    ];
}
/**
 * @return array{
 *     id: int, domain: string, site_type: string, php_version: ?string,
 *     repository_url: ?string, repository_branch: ?string, web_directory: ?string,
 *     status: ?string, raw: array<string, mixed>,
 * }
 */
function fakeSite(int $id, string $domain, string $type): array
{
    return [
        'id' => $id,
        'domain' => $domain,
        'site_type' => $type,
        'php_version' => '8.3',
        'repository_url' => 'git@github.com:acme/'.$domain.'.git',
        'repository_branch' => 'main',
        'web_directory' => '/public',
        'status' => 'installed',
        'raw' => ['id' => $id, 'domain' => $domain, 'kind' => $type],
    ];
}
/**
 * In-memory ImportDriver used by sync tests. Records nothing; just replays the
 * arrays the test seeded it with. Keeps the sync test independent of the Ploi
 * driver's normalisation logic (which has its own tests).
 */
final class FakeImportDriver implements ImportDriver
{
    /**
     * @param  list<array<string, mixed>>  $servers
     * @param  array<int, list<array<string, mixed>>>  $sitesByServer
     * @param  array<int, array<string, mixed>>  $serverDetail
     */
    function __construct(protected array $servers = [], protected array $sitesByServer = [], protected array $serverDetail = [])
    {
    }

    function source(): string
    {
        return 'ploi';
    }

    function validateConnection(): void
    {
    }

    function listServers(): array
    {
        return $this->servers;
    }

    function fetchServerDetail(int $sourceServerId): array
    {
        return $this->serverDetail[$sourceServerId]
            ?? throw new \RuntimeException("No fake detail for server {$sourceServerId}");
    }

    function listSites(int $sourceServerId): array
    {
        return $this->sitesByServer[$sourceServerId] ?? [];
    }

    function fetchSiteDetail(int $sourceServerId, int $sourceSiteId): array
    {
        throw new \RuntimeException('not used in these tests');
    }

    function pushSshKey(int $sourceServerId, string $label, string $publicKey): int
    {
        return 0;
    }

    function revokeSshKey(int $sourceServerId, int $sourceKeyId): void
    {
    }

    function fetchEnv(int $sourceServerId, int $sourceSiteId): string
    {
        return '';
    }

    function listSiteCrons(int $sourceServerId, int $sourceSiteId): array
    {
        return [];
    }

    function listDaemons(int $sourceServerId, int $sourceSiteId): array
    {
        return [];
    }

    function listSiteDatabases(int $sourceServerId, int $sourceSiteId): array
    {
        return [];
    }

    function fetchSiteCertificate(int $sourceServerId, int $sourceSiteId): ?array
    {
        return null;
    }

    function enableSiteMaintenance(int $sourceServerId, int $sourceSiteId): void
    {
    }

    function disableSiteMaintenance(int $sourceServerId, int $sourceSiteId): void
    {
    }

    function listSiteWebhooks(int $sourceServerId, int $sourceSiteId): array
    {
        return [];
    }

    function deleteSiteWebhook(int $sourceServerId, int $sourceSiteId, int $webhookId): void
    {
    }
}
