<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Forge;

use App\Models\ProviderCredential;
use App\Services\Imports\Forge\ForgeImportDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ForgeImportDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function driver(): ForgeImportDriver
    {
        $credential = ProviderCredential::factory()->create([
            'provider' => 'forge',
            'credentials' => ['api_token' => 'forge_test_token'],
        ]);

        return ForgeImportDriver::for($credential);
    }

    public function test_for_rejects_non_forge_credential(): void
    {
        $credential = ProviderCredential::factory()->create([
            'provider' => 'ploi',
            'credentials' => ['api_token' => 'ploi_xxx'],
        ]);
        $this->expectException(\InvalidArgumentException::class);
        ForgeImportDriver::for($credential);
    }

    public function test_validate_connection_hits_servers_endpoint(): void
    {
        Http::fake([
            'https://forge.laravel.com/api/v1/servers' => Http::response(['servers' => []], 200),
        ]);
        $this->driver()->validateConnection();
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET'
            && $r->url() === 'https://forge.laravel.com/api/v1/servers'
            && $r->header('Authorization')[0] === 'Bearer forge_test_token');
    }

    public function test_list_servers_normalises_forge_envelope_and_php_version(): void
    {
        Http::fake([
            'https://forge.laravel.com/api/v1/servers' => Http::response([
                'servers' => [
                    [
                        'id' => 42,
                        'name' => 'prod-web-01',
                        'ip_address' => '203.0.113.10',
                        'provider' => 'digitalocean',
                        'size' => 's-2vcpu-4gb',
                        'php_version' => 'php82',
                        'is_ready' => true,
                    ],
                ],
            ], 200),
        ]);

        $servers = $this->driver()->listServers();

        $this->assertCount(1, $servers);
        $this->assertSame(42, $servers[0]['id']);
        $this->assertSame('digitalocean', $servers[0]['provider_label']);
        $this->assertSame(['8.2'], $servers[0]['php_versions']);
        $this->assertSame('active', $servers[0]['status']);
    }

    public function test_list_sites_maps_name_to_domain_and_project_type(): void
    {
        Http::fake([
            'https://forge.laravel.com/api/v1/servers/42/sites' => Http::response([
                'sites' => [
                    [
                        'id' => 100,
                        'name' => 'app.example.com',
                        'project_type' => 'laravel',
                        'php_version' => 'php83',
                        'repository' => 'acme/app',
                        'repository_provider' => 'github',
                        'repository_branch' => 'main',
                        'directory' => '/public',
                        'status' => 'installed',
                    ],
                    [
                        'id' => 101,
                        'name' => 'static.example.com',
                        'project_type' => 'html',
                    ],
                ],
            ], 200),
        ]);

        $sites = $this->driver()->listSites(42);

        $this->assertCount(2, $sites);
        $this->assertSame('app.example.com', $sites[0]['domain']);
        $this->assertSame('laravel', $sites[0]['site_type']);
        $this->assertSame('8.3', $sites[0]['php_version']);
        $this->assertSame('git@github.com:acme/app.git', $sites[0]['repository_url']);
        $this->assertSame('main', $sites[0]['repository_branch']);
        $this->assertSame('static', $sites[1]['site_type']);
    }

    public function test_fetch_env_returns_raw_body(): void
    {
        Http::fake([
            'https://forge.laravel.com/api/v1/servers/42/sites/100/env' => Http::response(
                "APP_ENV=production\nAPP_KEY=base64:abcd\n",
                200
            ),
        ]);

        $env = $this->driver()->fetchEnv(42, 100);
        $this->assertSame("APP_ENV=production\nAPP_KEY=base64:abcd\n", $env);
    }

    public function test_list_site_crons_filters_server_level_jobs_by_site_directory(): void
    {
        Http::fake([
            'https://forge.laravel.com/api/v1/servers/42/sites/100' => Http::response([
                'site' => ['id' => 100, 'name' => 'app.example.com', 'directory' => '/public', 'user' => 'forge'],
            ], 200),
            'https://forge.laravel.com/api/v1/servers/42/jobs' => Http::response([
                'jobs' => [
                    ['id' => 1, 'command' => 'cd /home/forge/app.example.com && php artisan schedule:run', 'frequency' => '* * * * *', 'user' => 'forge'],
                    ['id' => 2, 'command' => 'cd /home/forge/other.example.com && php artisan queue:work', 'frequency' => '0 * * * *', 'user' => 'forge'],
                    ['id' => 3, 'command' => 'echo unrelated', 'frequency' => '0 0 * * *', 'user' => 'root'],
                ],
            ], 200),
        ]);

        $crons = $this->driver()->listSiteCrons(42, 100);

        $this->assertCount(1, $crons);
        $this->assertSame(1, $crons[0]['id']);
        $this->assertStringContainsString('schedule:run', $crons[0]['command']);
    }

    public function test_list_daemons_filters_to_matching_site_directory(): void
    {
        Http::fake([
            'https://forge.laravel.com/api/v1/servers/42/sites/100' => Http::response([
                'site' => ['id' => 100, 'name' => 'app.example.com', 'directory' => '/public', 'user' => 'forge'],
            ], 200),
            'https://forge.laravel.com/api/v1/servers/42/daemons' => Http::response([
                'daemons' => [
                    ['id' => 1, 'command' => 'php artisan horizon', 'directory' => '/home/forge/app.example.com', 'user' => 'forge', 'processes' => 1],
                    ['id' => 2, 'command' => 'php worker.php', 'directory' => '/home/forge/other-site', 'user' => 'forge', 'processes' => 1],
                ],
            ], 200),
        ]);

        $daemons = $this->driver()->listDaemons(42, 100);

        $this->assertCount(1, $daemons);
        $this->assertSame(1, $daemons[0]['id']);
        $this->assertSame('/home/forge/app.example.com', $daemons[0]['directory']);
    }

    public function test_list_site_databases_matches_users_to_site_user(): void
    {
        Http::fake([
            'https://forge.laravel.com/api/v1/servers/42/sites/100' => Http::response([
                'site' => ['id' => 100, 'name' => 'app.example.com', 'user' => 'forge'],
            ], 200),
            'https://forge.laravel.com/api/v1/servers/42/databases' => Http::response([
                'databases' => [
                    ['id' => 7, 'name' => 'app_db', 'users' => [['id' => 1, 'name' => 'forge']]],
                    ['id' => 8, 'name' => 'other_db', 'users' => [['id' => 2, 'name' => 'someone-else']]],
                ],
            ], 200),
        ]);

        $dbs = $this->driver()->listSiteDatabases(42, 100);

        $this->assertCount(1, $dbs);
        $this->assertSame('app_db', $dbs[0]['name']);
        $this->assertSame('forge', $dbs[0]['username']);
    }

    public function test_fetch_site_certificate_returns_active_letsencrypt_when_present(): void
    {
        Http::fake([
            'https://forge.laravel.com/api/v1/servers/42/sites/100/certificates' => Http::response([
                'certificates' => [
                    ['id' => 1, 'type' => 'letsencrypt', 'domain' => 'app.example.com', 'expires_at' => '2027-01-01T00:00:00Z', 'active' => true],
                ],
            ], 200),
        ]);

        $cert = $this->driver()->fetchSiteCertificate(42, 100);
        $this->assertNotNull($cert);
        $this->assertSame('letsencrypt', $cert['issuer']);
        $this->assertSame('active', $cert['status']);
    }

    public function test_fetch_site_certificate_returns_null_on_404(): void
    {
        Http::fake([
            'https://forge.laravel.com/api/v1/servers/42/sites/100/certificates' => Http::response('', 404),
        ]);
        $this->assertNull($this->driver()->fetchSiteCertificate(42, 100));
    }

    public function test_enable_maintenance_posts_to_integration_endpoint(): void
    {
        Http::fake([
            'https://forge.laravel.com/api/v1/servers/42/sites/100/integrations/laravel-maintenance' => Http::response('', 204),
        ]);

        $this->driver()->enableSiteMaintenance(42, 100);

        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && str_ends_with($r->url(), '/integrations/laravel-maintenance'));
    }

    public function test_push_ssh_key_extracts_id_from_key_envelope(): void
    {
        Http::fake([
            'https://forge.laravel.com/api/v1/servers/42/keys' => Http::response([
                'key' => ['id' => 9001, 'name' => 'dply-migrate-xyz'],
            ], 200),
        ]);

        $id = $this->driver()->pushSshKey(42, 'dply-migrate-xyz', 'ssh-ed25519 AAA...');
        $this->assertSame(9001, $id);
    }

    public function test_revoke_ssh_key_deletes_resource(): void
    {
        Http::fake([
            'https://forge.laravel.com/api/v1/servers/42/keys/9001' => Http::response('', 204),
        ]);
        $this->driver()->revokeSshKey(42, 9001);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE'
            && $r->url() === 'https://forge.laravel.com/api/v1/servers/42/keys/9001');
    }
}
