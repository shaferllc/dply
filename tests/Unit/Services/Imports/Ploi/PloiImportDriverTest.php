<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Ploi\PloiImportDriverTest;

use App\Models\ProviderCredential;
use App\Services\Imports\Ploi\PloiImportDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function driver(): PloiImportDriver
{
    $credential = ProviderCredential::factory()->create([
        'provider' => 'ploi',
        'credentials' => ['api_token' => 'ploi_test_token'],
    ]);

    return PloiImportDriver::for($credential);
}
test('for rejects non ploi credential', function () {
    $credential = ProviderCredential::factory()->create([
        'provider' => 'digitalocean',
        'credentials' => ['api_token' => 'dop_v1_xyz'],
    ]);

    $this->expectException(\InvalidArgumentException::class);
    PloiImportDriver::for($credential);
});
test('validate connection hits user endpoint', function () {
    Http::fake([
        'https://ploi.io/api/user' => Http::response(['data' => ['id' => 1, 'email' => 'x@y.z']], 200),
    ]);

    driver()->validateConnection();

    Http::assertSent(fn (Request $req): bool => $req->method() === 'GET'
        && $req->url() === 'https://ploi.io/api/user');
});
test('list servers normalises ploi payload', function () {
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

    $servers = driver()->listServers();

    expect($servers)->toHaveCount(2);
    expect($servers[0]['id'])->toBe(42);
    expect($servers[0]['name'])->toBe('prod-web-01');
    expect($servers[0]['provider_label'])->toBe('digital-ocean');
    expect($servers[0]['server_type'])->toBe('s-2vcpu-4gb');
    expect($servers[0]['php_versions'])->toBe(['8.3']);
    expect($servers[1]['php_versions'])->toBe(['8.2', '8.3']);
    expect($servers[0]['raw'])->toBeArray();
    expect($servers[0]['raw']['id'])->toBe(42);
});
test('list servers paginates', function () {
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

    $servers = driver()->listServers();

    expect($servers)->toHaveCount(2);
    expect(array_column($servers, 'id'))->toBe([1, 2]);
});
test('list sites normalises repository', function () {
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

    $sites = driver()->listSites(42);

    expect($sites)->toHaveCount(2);
    expect($sites[0]['domain'])->toBe('app.example.com');
    expect($sites[0]['site_type'])->toBe('laravel');
    expect($sites[0]['repository_url'])->toBe('git@github.com:acme/app.git');
    expect($sites[0]['repository_branch'])->toBe('main');
    expect($sites[1]['site_type'])->toBe('static');
    expect($sites[1]['repository_url'])->toBeNull();
});
test('fetch server detail unwraps data envelope', function () {
    Http::fake([
        'https://ploi.io/api/servers/42' => Http::response([
            'data' => ['id' => 42, 'name' => 'prod-web-01', 'ip_address' => '203.0.113.10'],
        ], 200),
    ]);

    $server = driver()->fetchServerDetail(42);

    expect($server['id'])->toBe(42);
    expect($server['ip_address'])->toBe('203.0.113.10');
});
test('push ssh key returns source side id', function () {
    Http::fake([
        'https://ploi.io/api/servers/42/keys' => Http::response([
            'data' => ['id' => 9001, 'name' => 'dply-migrate-abc'],
        ], 201),
    ]);

    $id = driver()->pushSshKey(42, 'dply-migrate-abc', 'ssh-ed25519 AAAAC3...');

    expect($id)->toBe(9001);
    Http::assertSent(fn (Request $req): bool => $req->method() === 'POST'
        && $req->url() === 'https://ploi.io/api/servers/42/keys'
        && $req['name'] === 'dply-migrate-abc'
        && $req['key'] === 'ssh-ed25519 AAAAC3...');
});
test('revoke ssh key deletes targeted resource', function () {
    Http::fake([
        'https://ploi.io/api/servers/42/keys/9001' => Http::response('', 204),
    ]);

    driver()->revokeSshKey(42, 9001);

    Http::assertSent(fn (Request $req): bool => $req->method() === 'DELETE'
        && $req->url() === 'https://ploi.io/api/servers/42/keys/9001');
});
