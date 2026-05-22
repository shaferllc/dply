<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Forge\ForgeImportDriverTest;

use App\Models\ProviderCredential;
use App\Services\Imports\Forge\ForgeImportDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function driver(): ForgeImportDriver
{
    $credential = ProviderCredential::factory()->create([
        'provider' => 'forge',
        'credentials' => ['api_token' => 'forge_test_token'],
    ]);

    return ForgeImportDriver::for($credential);
}
test('for rejects non forge credential', function () {
    $credential = ProviderCredential::factory()->create([
        'provider' => 'ploi',
        'credentials' => ['api_token' => 'ploi_xxx'],
    ]);
    $this->expectException(\InvalidArgumentException::class);
    ForgeImportDriver::for($credential);
});
test('validate connection hits servers endpoint', function () {
    Http::fake([
        'https://forge.laravel.com/api/v1/servers' => Http::response(['servers' => []], 200),
    ]);
    driver()->validateConnection();
    Http::assertSent(fn (Request $r): bool => $r->method() === 'GET'
        && $r->url() === 'https://forge.laravel.com/api/v1/servers'
        && $r->header('Authorization')[0] === 'Bearer forge_test_token');
});
test('list servers normalises forge envelope and php version', function () {
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

    $servers = driver()->listServers();

    expect($servers)->toHaveCount(1);
    expect($servers[0]['id'])->toBe(42);
    expect($servers[0]['provider_label'])->toBe('digitalocean');
    expect($servers[0]['php_versions'])->toBe(['8.2']);
    expect($servers[0]['status'])->toBe('active');
});
test('list sites maps name to domain and project type', function () {
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

    $sites = driver()->listSites(42);

    expect($sites)->toHaveCount(2);
    expect($sites[0]['domain'])->toBe('app.example.com');
    expect($sites[0]['site_type'])->toBe('laravel');
    expect($sites[0]['php_version'])->toBe('8.3');
    expect($sites[0]['repository_url'])->toBe('git@github.com:acme/app.git');
    expect($sites[0]['repository_branch'])->toBe('main');
    expect($sites[1]['site_type'])->toBe('static');
});
test('fetch env returns raw body', function () {
    Http::fake([
        'https://forge.laravel.com/api/v1/servers/42/sites/100/env' => Http::response(
            "APP_ENV=production\nAPP_KEY=base64:abcd\n",
            200
        ),
    ]);

    $env = driver()->fetchEnv(42, 100);
    expect($env)->toBe("APP_ENV=production\nAPP_KEY=base64:abcd\n");
});
test('list site crons filters server level jobs by site directory', function () {
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

    $crons = driver()->listSiteCrons(42, 100);

    expect($crons)->toHaveCount(1);
    expect($crons[0]['id'])->toBe(1);
    $this->assertStringContainsString('schedule:run', $crons[0]['command']);
});
test('list daemons filters to matching site directory', function () {
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

    $daemons = driver()->listDaemons(42, 100);

    expect($daemons)->toHaveCount(1);
    expect($daemons[0]['id'])->toBe(1);
    expect($daemons[0]['directory'])->toBe('/home/forge/app.example.com');
});
test('list site databases matches users to site user', function () {
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

    $dbs = driver()->listSiteDatabases(42, 100);

    expect($dbs)->toHaveCount(1);
    expect($dbs[0]['name'])->toBe('app_db');
    expect($dbs[0]['username'])->toBe('forge');
});
test('fetch site certificate returns active letsencrypt when present', function () {
    Http::fake([
        'https://forge.laravel.com/api/v1/servers/42/sites/100/certificates' => Http::response([
            'certificates' => [
                ['id' => 1, 'type' => 'letsencrypt', 'domain' => 'app.example.com', 'expires_at' => '2027-01-01T00:00:00Z', 'active' => true],
            ],
        ], 200),
    ]);

    $cert = driver()->fetchSiteCertificate(42, 100);
    expect($cert)->not->toBeNull();
    expect($cert['issuer'])->toBe('letsencrypt');
    expect($cert['status'])->toBe('active');
});
test('fetch site certificate returns null on 404', function () {
    Http::fake([
        'https://forge.laravel.com/api/v1/servers/42/sites/100/certificates' => Http::response('', 404),
    ]);
    expect(driver()->fetchSiteCertificate(42, 100))->toBeNull();
});
test('enable maintenance posts to integration endpoint', function () {
    Http::fake([
        'https://forge.laravel.com/api/v1/servers/42/sites/100/integrations/laravel-maintenance' => Http::response('', 204),
    ]);

    driver()->enableSiteMaintenance(42, 100);

    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
        && str_ends_with($r->url(), '/integrations/laravel-maintenance'));
});
test('push ssh key extracts id from key envelope', function () {
    Http::fake([
        'https://forge.laravel.com/api/v1/servers/42/keys' => Http::response([
            'key' => ['id' => 9001, 'name' => 'dply-migrate-xyz'],
        ], 200),
    ]);

    $id = driver()->pushSshKey(42, 'dply-migrate-xyz', 'ssh-ed25519 AAA...');
    expect($id)->toBe(9001);
});
test('revoke ssh key deletes resource', function () {
    Http::fake([
        'https://forge.laravel.com/api/v1/servers/42/keys/9001' => Http::response('', 204),
    ]);
    driver()->revokeSshKey(42, 9001);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE'
        && $r->url() === 'https://forge.laravel.com/api/v1/servers/42/keys/9001');
});
