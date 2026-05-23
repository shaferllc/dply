<?php

namespace Tests\Unit\Services\HetznerServiceTest;

use App\Models\ProviderCredential;
use App\Services\HetznerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('add ssh key posts public key to hetzner api', function () {
    Http::fake([
        'https://api.hetzner.cloud/v1/ssh_keys' => Http::response([
            'ssh_key' => ['id' => 99, 'name' => 'dply-key'],
        ], 201),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'hetzner',
        'credentials' => ['api_token' => 'hzn_test'],
    ]);

    $key = (new HetznerService($credential))->addSshKey('dply-key', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5 test');

    expect($key['id'])->toBe(99);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.hetzner.cloud/v1/ssh_keys'
        && $request->data()['name'] === 'dply-key'
        && str_contains($request->data()['public_key'], 'ssh-ed25519'));
});

test('create instance sends ssh key ids not raw public keys', function () {
    Http::fake([
        'https://api.hetzner.cloud/v1/servers' => Http::response([
            'server' => ['id' => 1234],
        ], 201),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'hetzner',
        'credentials' => ['api_token' => 'hzn_test'],
    ]);

    $id = (new HetznerService($credential))->createInstance(
        name: 'web-1',
        location: 'fsn1',
        serverType: 'cx22',
        image: 'ubuntu-24.04',
        sshKeyIds: [99],
    );

    expect($id)->toBe(1234);

    Http::assertSent(fn ($request) => $request->data()['ssh_keys'] === [99]
        && $request->data()['location'] === 'fsn1'
        && $request->data()['server_type'] === 'cx22');
});

test('get public ip extracts ipv4 from server payload', function () {
    $ip = HetznerService::getPublicIp([
        'public_net' => [
            'ipv4' => ['ip' => '203.0.113.5'],
        ],
    ]);

    expect($ip)->toBe('203.0.113.5');
});

test('validate token calls servers list endpoint', function () {
    Http::fake([
        'https://api.hetzner.cloud/v1/servers*' => Http::response(['servers' => []], 200),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'hetzner',
        'credentials' => ['api_token' => 'hzn_test'],
    ]);

    (new HetznerService($credential))->validateToken();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/servers'));
});

test('destroy instance deletes server by id', function () {
    Http::fake([
        'https://api.hetzner.cloud/v1/servers/555' => Http::response([], 200),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'hetzner',
        'credentials' => ['api_token' => 'hzn_test'],
    ]);

    (new HetznerService($credential))->destroyInstance(555);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.hetzner.cloud/v1/servers/555');
});
