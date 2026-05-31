<?php

namespace Tests\Unit\Services\LinodeServiceTest;

use App\Models\ProviderCredential;
use App\Services\LinodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('create instance sends authorized keys not ssh key ids', function () {
    Http::fake([
        'https://api.linode.com/v4/linode/instances' => Http::response([
            'id' => 1234,
        ], 200),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'linode',
        'credentials' => ['api_token' => 'lin_test'],
    ]);

    $id = (new LinodeService($credential))->createInstance(
        label: 'web-1',
        region: 'us-east',
        type: 'g6-nanode-1',
        image: 'linode/ubuntu24.04',
        authorizedKeys: ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5 test'],
    );

    expect($id)->toBe(1234);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.linode.com/v4/linode/instances'
        && ($request->data()['authorized_keys'] ?? []) === ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5 test']
        && $request->data()['region'] === 'us-east'
        && $request->data()['type'] === 'g6-nanode-1');
});

test('get public ip extracts ipv4 from string array', function () {
    $ip = LinodeService::getPublicIp([
        'ipv4' => ['203.0.113.5'],
    ]);

    expect($ip)->toBe('203.0.113.5');
});

test('get public ip extracts ipv4 from nested address array', function () {
    $ip = LinodeService::getPublicIp([
        'ipv4' => [
            ['address' => '203.0.113.6'],
        ],
    ]);

    expect($ip)->toBe('203.0.113.6');
});

test('validate token calls profile endpoint', function () {
    Http::fake([
        'https://api.linode.com/v4/profile' => Http::response(['username' => 'test'], 200),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'linode',
        'credentials' => ['api_token' => 'lin_test'],
    ]);

    (new LinodeService($credential))->validateToken();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.linode.com/v4/profile');
});

test('destroy instance deletes linode by id', function () {
    Http::fake([
        'https://api.linode.com/v4/linode/instances/555' => Http::response([], 200),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'linode',
        'credentials' => ['api_token' => 'lin_test'],
    ]);

    (new LinodeService($credential))->destroyInstance(555);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.linode.com/v4/linode/instances/555');
});

test('domain exists checks linode dns domains', function () {
    Http::fake([
        'https://api.linode.com/v4/domains*' => Http::response([
            'data' => [
                ['id' => 10, 'domain' => 'example.com'],
            ],
            'pages' => 1,
        ], 200),
    ]);

    $credential = ProviderCredential::factory()->create([
        'provider' => 'linode',
        'credentials' => ['api_token' => 'lin_test'],
    ]);

    expect((new LinodeService($credential))->domainExists('example.com'))->toBeTrue();
    expect((new LinodeService($credential))->domainExists('missing.test'))->toBeFalse();
});

test('upsert domain record creates record when missing', function () {
    Http::fake(function ($request) {
        $url = $request->url();
        $method = $request->method();

        if (str_contains($url, '/domains/10/records') && $method === 'GET') {
            return Http::response(['data' => [], 'pages' => 1], 200);
        }

        if ($url === 'https://api.linode.com/v4/domains/10/records' && $method === 'POST') {
            return Http::response([
                'data' => ['id' => 55, 'type' => 'A', 'name' => 'preview', 'target' => '203.0.113.1'],
            ], 200);
        }

        if (str_contains($url, '/domains') && $method === 'GET') {
            return Http::response([
                'data' => [
                    ['id' => 10, 'domain' => 'example.com'],
                ],
                'pages' => 1,
            ], 200);
        }

        return Http::response([], 404);
    });

    $credential = ProviderCredential::factory()->create([
        'provider' => 'linode',
        'credentials' => ['api_token' => 'lin_test'],
    ]);

    $record = (new LinodeService($credential))->upsertDomainRecord('example.com', 'A', 'preview', '203.0.113.1');

    expect($record['id'] ?? null)->toBe(55);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.linode.com/v4/domains/10/records'
        && ($request->data()['target'] ?? null) === '203.0.113.1');
});

test('normalize record name maps apex hostnames to empty string', function () {
    expect(LinodeService::normalizeRecordName('example.com', 'example.com'))->toBe('');
    expect(LinodeService::normalizeRecordName('preview', 'example.com'))->toBe('preview');
});
