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

test('domain exists checks vultr dns domains', function () {
    Http::fake([
        'https://api.vultr.com/v2/domains/example.com' => Http::response([
            'domain' => ['domain' => 'example.com'],
        ], 200),
        'https://api.vultr.com/v2/domains/missing.test' => Http::response([], 404),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'vultr',
        'credentials' => ['api_token' => 'vultr_test'],
    ]);

    expect((new VultrService($credential))->domainExists('example.com'))->toBeTrue();
    expect((new VultrService($credential))->domainExists('missing.test'))->toBeFalse();
});

test('upsert domain record creates record when missing', function () {
    Http::fake(function ($request) {
        $url = $request->url();
        $method = $request->method();

        if (str_contains($url, '/domains/example.com/records') && $method === 'GET') {
            return Http::response(['records' => [], 'meta' => ['links' => ['next' => '']]], 200);
        }

        if ($url === 'https://api.vultr.com/v2/domains/example.com/records' && $method === 'POST') {
            return Http::response([
                'record' => ['id' => 'rec-55', 'type' => 'A', 'name' => 'preview', 'data' => '203.0.113.1'],
            ], 201);
        }

        return Http::response([], 404);
    });

    $credential = ProviderCredential::factory()->create([
        'provider' => 'vultr',
        'credentials' => ['api_token' => 'vultr_test'],
    ]);

    $record = (new VultrService($credential))->upsertDomainRecord('example.com', 'A', 'preview', '203.0.113.1');

    expect($record['id'] ?? null)->toBe('rec-55');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.vultr.com/v2/domains/example.com/records'
        && ($request->data()['data'] ?? null) === '203.0.113.1');
});

test('normalize record name maps apex hostnames to empty string', function () {
    expect(VultrService::normalizeRecordName('example.com', 'example.com'))->toBe('');
    expect(VultrService::normalizeRecordName('preview', 'example.com'))->toBe('preview');
});

test('from token builds a service bound to a raw api token', function () {
    Http::fake([
        'https://api.vultr.com/v2/account' => Http::response(['account' => []], 200),
    ]);

    VultrService::fromToken('base-key-123')->validateToken();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer base-key-123'));
});

test('from config returns null without a base key and a service when set', function () {
    config(['services.vultr.token' => '']);
    expect(VultrService::fromConfig())->toBeNull();

    config(['services.vultr.token' => 'global-tok']);
    expect(VultrService::fromConfig())->toBeInstanceOf(VultrService::class);
});

test('get private ip prefers internal_ip then falls back to vpc subnet', function () {
    expect(VultrService::getPrivateIp(['internal_ip' => '10.1.96.3']))->toBe('10.1.96.3');

    expect(VultrService::getPrivateIp([
        'internal_ip' => '',
        'vpcs' => [['id' => 'v1', 'subnet' => '10.2.0.5']],
    ]))->toBe('10.2.0.5');

    expect(VultrService::getPrivateIp(['internal_ip' => '', 'vpcs' => []]))->toBeNull();
});

test('create snapshot posts instance id and description and returns id', function () {
    Http::fake([
        'https://api.vultr.com/v2/snapshots' => Http::response([
            'snapshot' => ['id' => 'snap-77', 'status' => 'pending'],
        ], 201),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'vultr',
        'credentials' => ['api_token' => 'vultr_test'],
    ]);

    $id = (new VultrService($credential))->createSnapshot('vps-1234', 'nightly');

    expect($id)->toBe('snap-77');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.vultr.com/v2/snapshots'
        && $request->data()['instance_id'] === 'vps-1234'
        && $request->data()['description'] === 'nightly');
});

test('wait for snapshot returns once status is complete', function () {
    Http::fake([
        'https://api.vultr.com/v2/snapshots/snap-77' => Http::response([
            'snapshot' => ['id' => 'snap-77', 'status' => 'complete', 'size' => 42949672960, 'compressed_size' => 949678560],
        ], 200),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'vultr',
        'credentials' => ['api_token' => 'vultr_test'],
    ]);

    $snapshot = (new VultrService($credential))->waitForSnapshot('snap-77');

    expect($snapshot['status'])->toBe('complete')
        ->and($snapshot['compressed_size'])->toBe(949678560);
});

test('delete snapshot deletes by id and treats 404 as success', function () {
    Http::fake([
        'https://api.vultr.com/v2/snapshots/snap-gone' => Http::response([], 404),
        'https://api.vultr.com/v2/snapshots/snap-77' => Http::response([], 204),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'vultr',
        'credentials' => ['api_token' => 'vultr_test'],
    ]);

    (new VultrService($credential))->deleteSnapshot('snap-77');
    (new VultrService($credential))->deleteSnapshot('snap-gone'); // must not throw

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.vultr.com/v2/snapshots/snap-77');
});
