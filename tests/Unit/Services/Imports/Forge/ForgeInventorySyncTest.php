<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Forge;

use App\Models\ForgeServer;
use App\Models\ForgeSite;
use App\Models\ProviderCredential;
use App\Services\Imports\Forge\ForgeInventorySync;
use App\Services\Imports\ImportDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForgeInventorySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function credential(): ProviderCredential
    {
        return ProviderCredential::factory()->create([
            'provider' => 'forge',
            'credentials' => ['api_token' => 'forge_token'],
        ]);
    }

    public function test_sync_all_inserts_new_servers_and_sites(): void
    {
        $driver = new FakeForgeDriver(
            servers: [
                $this->server(42, 'prod-web-01'),
                $this->server(43, 'prod-web-02'),
            ],
            sitesByServer: [
                42 => [$this->site(100, 'app.example.com', 'laravel')],
                43 => [
                    $this->site(200, 'api.example.com', 'php'),
                    $this->site(201, 'static.example.com', 'static'),
                ],
            ],
        );

        $sync = new ForgeInventorySync($driver);
        $result = $sync->syncAll($this->credential());

        $this->assertSame(2, $result->serversSeen);
        $this->assertSame(3, $result->sitesSeen);
        $this->assertDatabaseCount('forge_servers', 2);
        $this->assertDatabaseCount('forge_sites', 3);
    }

    public function test_sync_all_marks_missing_rows_removed_and_cascades(): void
    {
        $credential = $this->credential();
        $driver = new FakeForgeDriver(
            servers: [$this->server(42, 'a'), $this->server(43, 'b')],
            sitesByServer: [
                42 => [$this->site(100, 'x.com', 'laravel')],
                43 => [$this->site(200, 'y.com', 'php')],
            ],
        );
        (new ForgeInventorySync($driver))->syncAll($credential);

        // Second pull: only server 42 remains.
        $reduced = new FakeForgeDriver(
            servers: [$this->server(42, 'a')],
            sitesByServer: [42 => [$this->site(100, 'x.com', 'laravel')]],
        );
        (new ForgeInventorySync($reduced))->syncAll($credential);

        $this->assertDatabaseHas('forge_servers', ['source_id' => 42, 'removed_from_source' => false]);
        $this->assertDatabaseHas('forge_servers', ['source_id' => 43, 'removed_from_source' => true]);
        $this->assertDatabaseHas('forge_sites', ['source_id' => 200, 'removed_from_source' => true]);
        $this->assertDatabaseHas('forge_sites', ['source_id' => 100, 'removed_from_source' => false]);
    }

    public function test_sync_rejects_non_forge_credential(): void
    {
        $credential = ProviderCredential::factory()->create(['provider' => 'ploi']);
        $this->expectException(\RuntimeException::class);
        (new ForgeInventorySync(new FakeForgeDriver()))->syncAll($credential);
    }

    public function test_sync_one_server_scopes_removals_to_that_server(): void
    {
        $credential = $this->credential();
        (new ForgeInventorySync(new FakeForgeDriver(
            servers: [$this->server(42, 'a'), $this->server(43, 'b')],
            sitesByServer: [
                42 => [$this->site(100, 'x.com', 'laravel')],
                43 => [$this->site(200, 'y.com', 'php')],
            ],
        )))->syncAll($credential);

        $detailDriver = new FakeForgeDriver(
            serverDetail: [42 => $this->server(42, 'a-renamed')],
            sitesByServer: [42 => [$this->site(100, 'x.com', 'laravel')]],
        );
        $result = (new ForgeInventorySync($detailDriver))->syncOneServer($credential, 42);

        $this->assertSame(1, $result->serversSeen);
        $this->assertDatabaseHas('forge_servers', ['source_id' => 42, 'name' => 'a-renamed', 'removed_from_source' => false]);
        $this->assertDatabaseHas('forge_servers', ['source_id' => 43, 'removed_from_source' => false]);
        $this->assertDatabaseHas('forge_sites', ['source_id' => 200, 'removed_from_source' => false]);
    }

    /**
     * @return array{
     *     id: int, name: string, ip_address: ?string, provider_label: ?string,
     *     server_type: ?string, php_versions: list<string>, status: ?string,
     *     raw: array<string, mixed>,
     * }
     */
    protected function server(int $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'ip_address' => '203.0.113.'.($id % 200),
            'provider_label' => 'digitalocean',
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
    protected function site(int $id, string $domain, string $type): array
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
            'raw' => ['id' => $id, 'name' => $domain, 'project_type' => $type],
        ];
    }
}

final class FakeForgeDriver implements ImportDriver
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
