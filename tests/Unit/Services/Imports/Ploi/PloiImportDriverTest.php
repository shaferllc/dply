<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Ploi;

use App\Models\ProviderCredential;
use App\Services\Imports\Ploi\PloiImportDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PloiImportDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function driver(): PloiImportDriver
    {
        $credential = ProviderCredential::factory()->create([
            'provider' => 'ploi',
            'credentials' => ['api_token' => 'ploi_test_token'],
        ]);

        return PloiImportDriver::for($credential);
    }

    public function test_for_rejects_non_ploi_credential(): void
    {
        $credential = ProviderCredential::factory()->create([
            'provider' => 'digitalocean',
            'credentials' => ['api_token' => 'dop_v1_xyz'],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        PloiImportDriver::for($credential);
    }

    public function test_validate_connection_hits_user_endpoint(): void
    {
        Http::fake([
            'https://ploi.io/api/user' => Http::response(['data' => ['id' => 1, 'email' => 'x@y.z']], 200),
        ]);

        $this->driver()->validateConnection();

        Http::assertSent(fn (Request $req): bool => $req->method() === 'GET'
            && $req->url() === 'https://ploi.io/api/user');
    }

    public function test_list_servers_normalises_ploi_payload(): void
    {
        Http::fake([
            'https://ploi.io/api/servers*' => Http::response([
                'data' => [
                    [
                        'id' => 42,
                        'name' => 'prod-web-01',
                        'ip_address' => '203.0.113.10',
                        'type' => 'digital-ocean',
                        'server_type' => 's-2vcpu-4gb',
                        'php_version' => '8.3',
                        'status' => 'active',
                    ],
                    [
                        'id' => 43,
                        'name' => 'prod-web-02',
                        'ip_address' => '203.0.113.11',
                        'type' => 'hetzner',
                        'php_versions' => ['8.2', '8.3'],
                        'status' => 'active',
                    ],
                ],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200),
        ]);

        $servers = $this->driver()->listServers();

        $this->assertCount(2, $servers);
        $this->assertSame(42, $servers[0]['id']);
        $this->assertSame('prod-web-01', $servers[0]['name']);
        $this->assertSame('digital-ocean', $servers[0]['provider_label']);
        $this->assertSame('s-2vcpu-4gb', $servers[0]['server_type']);
        $this->assertSame(['8.3'], $servers[0]['php_versions']);
        $this->assertSame(['8.2', '8.3'], $servers[1]['php_versions']);
        $this->assertIsArray($servers[0]['raw']);
        $this->assertSame(42, $servers[0]['raw']['id']);
    }

    public function test_list_servers_paginates(): void
    {
        Http::fake([
            'https://ploi.io/api/servers?page=1' => Http::response([
                'data' => [['id' => 1, 'name' => 'a']],
                'meta' => ['current_page' => 1, 'last_page' => 2],
            ], 200),
            'https://ploi.io/api/servers?page=2' => Http::response([
                'data' => [['id' => 2, 'name' => 'b']],
                'meta' => ['current_page' => 2, 'last_page' => 2],
            ], 200),
        ]);

        $servers = $this->driver()->listServers();

        $this->assertCount(2, $servers);
        $this->assertSame([1, 2], array_column($servers, 'id'));
    }

    public function test_list_sites_normalises_repository(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/42/sites*' => Http::response([
                'data' => [
                    [
                        'id' => 100,
                        'domain' => 'app.example.com',
                        'kind' => 'laravel',
                        'php_version' => '8.3',
                        'repository' => 'acme/app',
                        'repository_provider' => 'github',
                        'branch' => 'main',
                        'web_directory' => '/public',
                        'status' => 'installed',
                    ],
                    [
                        'id' => 101,
                        'domain' => 'static.example.com',
                        'kind' => 'static',
                    ],
                ],
            ], 200),
        ]);

        $sites = $this->driver()->listSites(42);

        $this->assertCount(2, $sites);
        $this->assertSame('app.example.com', $sites[0]['domain']);
        $this->assertSame('laravel', $sites[0]['site_type']);
        $this->assertSame('git@github.com:acme/app.git', $sites[0]['repository_url']);
        $this->assertSame('main', $sites[0]['repository_branch']);
        $this->assertSame('static', $sites[1]['site_type']);
        $this->assertNull($sites[1]['repository_url']);
    }

    public function test_fetch_server_detail_unwraps_data_envelope(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/42' => Http::response([
                'data' => ['id' => 42, 'name' => 'prod-web-01', 'ip_address' => '203.0.113.10'],
            ], 200),
        ]);

        $server = $this->driver()->fetchServerDetail(42);

        $this->assertSame(42, $server['id']);
        $this->assertSame('203.0.113.10', $server['ip_address']);
    }

    public function test_push_ssh_key_returns_source_side_id(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/42/keys' => Http::response([
                'data' => ['id' => 9001, 'name' => 'dply-migrate-abc'],
            ], 201),
        ]);

        $id = $this->driver()->pushSshKey(42, 'dply-migrate-abc', 'ssh-ed25519 AAAAC3...');

        $this->assertSame(9001, $id);
        Http::assertSent(fn (Request $req): bool => $req->method() === 'POST'
            && $req->url() === 'https://ploi.io/api/servers/42/keys'
            && $req['name'] === 'dply-migrate-abc'
            && $req['key'] === 'ssh-ed25519 AAAAC3...');
    }

    public function test_revoke_ssh_key_deletes_targeted_resource(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/42/keys/9001' => Http::response('', 204),
        ]);

        $this->driver()->revokeSshKey(42, 9001);

        Http::assertSent(fn (Request $req): bool => $req->method() === 'DELETE'
            && $req->url() === 'https://ploi.io/api/servers/42/keys/9001');
    }
}
