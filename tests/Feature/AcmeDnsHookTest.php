<?php

declare(strict_types=1);

use App\Http\Controllers\AcmeDnsHookController;
use App\Modules\Providers\Namecheap\NamecheapDnsService;
use Illuminate\Support\Facades\Http;

test('acme dns hook rejects a bad signature', function () {
    $response = $this->postJson('/hooks/acme-dns', [
        'action' => 'set',
        'zone' => 'dply.test',
        'name' => '_acme-challenge',
        'value' => 'abc',
    ], [
        'X-Dply-Signature' => 'nope',
    ]);

    $response->assertForbidden();
});

test('acme dns hook writes a namecheap txt record', function () {
    config([
        'services.cloudflare.vm' => ['dply.test'],
        'services.cloudflare.edge' => [],
        'services.cloudflare.serverless' => [],
        'services.namecheap.api_user' => 'user',
        'services.namecheap.api_key' => 'key',
        'services.namecheap.client_ip' => '1.2.3.4',
        'app.key' => 'base64:u1rqFSyAdyOMVZ2u/2GSG1wWpF/5VtJMAhxdbnn2LB8=',
    ]);

    Http::fake([
        'api.namecheap.com/*' => Http::sequence()
            ->push('<?xml version="1.0"?><ApiResponse Status="OK"><CommandResponse><DomainDNSGetHostsResult EmailType="FWD"><host Name="@" Type="A" Address="1.1.1.1" MXPref="10" TTL="1799"/></DomainDNSGetHostsResult></CommandResponse></ApiResponse>', 200)
            ->push('<?xml version="1.0"?><ApiResponse Status="OK"><CommandResponse></CommandResponse></ApiResponse>', 200),
    ]);

    $body = json_encode([
        'action' => 'set',
        'zone' => 'dply.test',
        'name' => '_acme-challenge',
        'value' => 'challenge-token',
    ], JSON_THROW_ON_ERROR);

    $response = $this->call('POST', '/hooks/acme-dns', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_DPLY_SIGNATURE' => hash_hmac('sha256', $body, AcmeDnsHookController::hookSecret()),
    ], $body);

    $response->assertOk()->assertJson(['ok' => true]);
    expect(NamecheapDnsService::isConfigured())->toBeTrue();
});
