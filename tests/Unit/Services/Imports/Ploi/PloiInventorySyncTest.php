<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Ploi;

use App\Models\PloiServer;
use App\Models\PloiSite;
use App\Models\ProviderCredential;
use App\Services\Imports\ImportDriver;
use App\Services\Imports\Ploi\PloiInventorySync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PloiInventorySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function credential(): ProviderCredential
    {
        return ProviderCredential::factory()->create([
            'provider' => 'ploi',
            'credentials' => ['api_token' => 'ploi_test_token'],
        ]);
    }

    public function test_sync_all_inserts_new_servers_and_sites(): void
    {
        $driver = new FakeImportDriver(
            servers: [
                $this->fakeServer(42, 'prod-web-01'),
                $this->fakeServer(43, 'prod-web-02'),
            ],
            sitesByServer: [
                42 => [
                    $this->fakeSite(100, 'app.example.com', 'laravel'),
                    $this->fakeSite(101, 'static.example.com', 'static'),
                ],
                43 => [
                    $this->fakeSite(200, 'api.example.com', 'php'),
                ],
            ],
        );

        $sync = new PloiInventorySync($driver);
        $result = $sync->syncAll($this->credential());

        $this->assertSame(2, $result->serversSeen);
        $this->assertSame(3, $result->sitesSeen);
        $this->assertDatabaseCount('ploi_servers', 2);
        $this->assertDatabaseCount('ploi_sites', 3);
        $this->assertDatabaseHas('ploi_servers', ['source_id' => 42, 'name' => 'prod-web-01']);
        $this->assertDatabaseHas('ploi_sites', ['source_id' => 100, 'domain' => 'app.example.com', 'site_type' => 'laravel']);
    }

    public function test_sync_all_updates_existing_rows_and_sets_last_synced_at(): void
    {
        $credential = $this->credential();
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
            servers: [$this->fakeServer(42, 'new-name')],
            sitesByServer: [42 => []],
        );

        (new PloiInventorySync($driver))->syncAll($credential);

        $this->assertDatabaseCount('ploi_servers', 1);
        $reloaded = PloiServer::find($existing->id);
        $this->assertSame('new-name', $reloaded->name);
        $this->assertNotNull($reloaded->last_synced_at);
    }

    public function test_sync_all_marks_missing_servers_and_sites_removed(): void
    {
        $credential = $this->credential();

        // Seed: two servers, one site each — to be reduced to one server, no sites on the survivor.
        $driver = new FakeImportDriver(
            servers: [$this->fakeServer(42, 'a'), $this->fakeServer(43, 'b')],
            sitesByServer: [42 => [$this->fakeSite(100, 'x.com', 'laravel')], 43 => [$this->fakeSite(200, 'y.com', 'php')]],
        );
        (new PloiInventorySync($driver))->syncAll($credential);
        $this->assertDatabaseCount('ploi_servers', 2);
        $this->assertDatabaseCount('ploi_sites', 2);

        // Second pull: only server 42 remains, and it has no sites.
        $driverAfter = new FakeImportDriver(
            servers: [$this->fakeServer(42, 'a')],
            sitesByServer: [42 => []],
        );
        (new PloiInventorySync($driverAfter))->syncAll($credential);

        $this->assertDatabaseHas('ploi_servers', ['source_id' => 42, 'removed_from_source' => false]);
        $this->assertDatabaseHas('ploi_servers', ['source_id' => 43, 'removed_from_source' => true]);
        $this->assertDatabaseHas('ploi_sites', ['source_id' => 100, 'removed_from_source' => true]);
        $this->assertDatabaseHas('ploi_sites', ['source_id' => 200, 'removed_from_source' => true]);
    }

    public function test_sync_one_server_scopes_removals_to_that_server(): void
    {
        $credential = $this->credential();

        // Seed two servers via full sync.
        (new PloiInventorySync(new FakeImportDriver(
            servers: [$this->fakeServer(42, 'a'), $this->fakeServer(43, 'b')],
            sitesByServer: [42 => [$this->fakeSite(100, 'x.com', 'laravel')], 43 => [$this->fakeSite(200, 'y.com', 'php')]],
        )))->syncAll($credential);

        // Pre-migration re-sync of just server 42: site 100 should remain, server 43 must stay alive.
        $driver = new FakeImportDriver(
            serverDetail: [42 => $this->fakeServer(42, 'a-renamed')],
            sitesByServer: [42 => [$this->fakeSite(100, 'x.com', 'laravel')]],
        );
        $result = (new PloiInventorySync($driver))->syncOneServer($credential, 42);

        $this->assertSame(1, $result->serversSeen);
        $this->assertDatabaseHas('ploi_servers', ['source_id' => 42, 'name' => 'a-renamed', 'removed_from_source' => false]);
        $this->assertDatabaseHas('ploi_servers', ['source_id' => 43, 'name' => 'b', 'removed_from_source' => false]);
        $this->assertDatabaseHas('ploi_sites', ['source_id' => 200, 'removed_from_source' => false]);
    }

    public function test_sync_rejects_non_ploi_credential(): void
    {
        $credential = ProviderCredential::factory()->create([
            'provider' => 'digitalocean',
        ]);

        $this->expectException(\RuntimeException::class);
        (new PloiInventorySync(new FakeImportDriver()))->syncAll($credential);
    }

    /**
     * @return array{
     *     id: int, name: string, ip_address: ?string, provider_label: ?string,
     *     server_type: ?string, php_versions: list<string>, status: ?string,
     *     raw: array<string, mixed>,
     * }
     */
    protected function fakeServer(int $id, string $name): array
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
    protected function fakeSite(int $id, string $domain, string $type): array
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
    public function __construct(
        protected array $servers = [],
        protected array $sitesByServer = [],
        protected array $serverDetail = [],
    ) {}

    public function source(): string
    {
        return 'ploi';
    }

    public function validateConnection(): void {}

    public function listServers(): array
    {
        return $this->servers;
    }

    public function fetchServerDetail(int $sourceServerId): array
    {
        return $this->serverDetail[$sourceServerId]
            ?? throw new \RuntimeException("No fake detail for server {$sourceServerId}");
    }

    public function listSites(int $sourceServerId): array
    {
        return $this->sitesByServer[$sourceServerId] ?? [];
    }

    public function fetchSiteDetail(int $sourceServerId, int $sourceSiteId): array
    {
        throw new \RuntimeException('not used in these tests');
    }

    public function pushSshKey(int $sourceServerId, string $label, string $publicKey): int
    {
        return 0;
    }

    public function revokeSshKey(int $sourceServerId, int $sourceKeyId): void {}
}
