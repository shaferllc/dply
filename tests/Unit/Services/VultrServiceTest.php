<?php

namespace Tests\Unit\Services\VultrServiceTest;

use App\Models\ProviderCredential;
use App\Services\VultrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('create ssh key posts public key to vultr api', function () {
    Http::fake([
        'https://api.vultr.com/v2/ssh-keys' => Http::response([
            'ssh_key' => ['id' => 'ssh-99', 'name' => 'dply-key'],
        ], 201),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'vultr',
        'credentials' => ['api_token' => 'vultr_test'],
    ]);

    $id = (new VultrService($credential))->createSshKey('dply-key', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5 test');

    expect($id)->toBe('ssh-99');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.vultr.com/v2/ssh-keys'
        && $request->data()['name'] === 'dply-key'
        && str_contains($request->data()['ssh_key'], 'ssh-ed25519'));
});

test('create instance sends ssh key ids not raw public keys', function () {
    Http::fake([
        'https://api.vultr.com/v2/instances' => Http::response([
            'instance' => ['id' => 'vps-1234'],
        ], 201),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'vultr',
        'credentials' => ['api_token' => 'vultr_test'],
    ]);

    $id = (new VultrService($credential))->createInstance(
        region: 'ewr',
        plan: 'vc2-1c-1gb',
        osId: 2152,
        label: 'web-1',
        sshKeyIds: ['ssh-99'],
    );

    expect($id)->toBe('vps-1234');

    Http::assertSent(fn ($request) => $request->data()['sshkey_id'] === ['ssh-99']
        && $request->data()['region'] === 'ewr'
        && $request->data()['plan'] === 'vc2-1c-1gb');
});

test('get public ip extracts ipv4 from instance payload', function () {
    $ip = VultrService::getPublicIp([
        'main_ip' => '203.0.113.5',
    ]);

    expect($ip)->toBe('203.0.113.5');
});

test('validate token calls account endpoint', function () {
    Http::fake([
        'https://api.vultr.com/v2/account' => Http::response(['account' => []], 200),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'vultr',
        'credentials' => ['api_token' => 'vultr_test'],
    ]);

    (new VultrService($credential))->validateToken();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.vultr.com/v2/account');
});

test('destroy instance deletes server by id', function () {
    Http::fake([
        'https://api.vultr.com/v2/instances/vps-555' => Http::response([], 204),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'vultr',
        'credentials' => ['api_token' => 'vultr_test'],
    ]);

    (new VultrService($credential))->destroyInstance('vps-555');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.vultr.com/v2/instances/vps-555');
});
