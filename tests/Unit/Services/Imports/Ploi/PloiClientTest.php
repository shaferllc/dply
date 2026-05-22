<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Ploi\PloiClientTest;
use RuntimeException;

use App\Models\ProviderCredential;
use App\Services\Imports\Ploi\PloiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function makeCredential(): ProviderCredential
{
    return ProviderCredential::factory()->create([
        'provider' => 'ploi',
        'name' => 'Test Ploi',
        'credentials' => ['api_token' => 'ploi_test_token'],
    ]);
}
test('get sends bearer token against ploi base url', function () {
    Http::fake([
        'https://ploi.io/api/servers*' => Http::response(['data' => []], 200),
    ]);

    $client = new PloiClient(makeCredential());
    $client->get('/servers');

    Http::assertSent(function (Request $req): bool {
        return $req->method() === 'GET'
            && str_starts_with($req->url(), 'https://ploi.io/api/servers')
            && $req->header('Authorization')[0] === 'Bearer ploi_test_token'
            && $req->header('Accept')[0] === 'application/json';
    });
});
test('post sends json body', function () {
    Http::fake([
        'https://ploi.io/api/servers/9/keys' => Http::response(['data' => ['id' => 42]], 201),
    ]);

    $client = new PloiClient(makeCredential());
    $client->post('/servers/9/keys', ['name' => 'dply-migrate', 'key' => 'ssh-ed25519 AAA...']);

    Http::assertSent(function (Request $req): bool {
        return $req->method() === 'POST'
            && $req->url() === 'https://ploi.io/api/servers/9/keys'
            && $req['name'] === 'dply-migrate'
            && $req['key'] === 'ssh-ed25519 AAA...';
    });
});
test('delete targets resource', function () {
    Http::fake([
        'https://ploi.io/api/servers/9/keys/42' => Http::response('', 204),
    ]);

    $client = new PloiClient(makeCredential());
    $client->delete('/servers/9/keys/42');

    Http::assertSent(fn (Request $req): bool => $req->method() === 'DELETE'
        && $req->url() === 'https://ploi.io/api/servers/9/keys/42');
});
test('assert success throws on non 2xx', function () {
    $client = new PloiClient(makeCredential());
    Http::fake([
        'https://ploi.io/api/servers' => Http::response(['message' => 'Unauthenticated.'], 401),
    ]);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessageMatches('/HTTP 401/');
    $client->assertSuccess($client->get('/servers'), 'list servers');
});
test('constructor rejects blank token', function () {
    $credential = ProviderCredential::factory()->create([
        'provider' => 'ploi',
        'credentials' => ['api_token' => ''],
    ]);

    $this->expectException(\InvalidArgumentException::class);
    new PloiClient($credential);
});
