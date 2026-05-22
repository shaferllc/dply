<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Imports\Ploi;

use App\Models\ProviderCredential;
use App\Services\Imports\Ploi\PloiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PloiClientTest extends TestCase
{
    use RefreshDatabase;

    protected function makeCredential(): ProviderCredential
    {
        return ProviderCredential::factory()->create([
            'provider' => 'ploi',
            'name' => 'Test Ploi',
            'credentials' => ['api_token' => 'ploi_test_token'],
        ]);
    }

    public function test_get_sends_bearer_token_against_ploi_base_url(): void
    {
        Http::fake([
            'https://ploi.io/api/servers*' => Http::response(['data' => []], 200),
        ]);

        $client = new PloiClient($this->makeCredential());
        $client->get('/servers');

        Http::assertSent(function (Request $req): bool {
            return $req->method() === 'GET'
                && str_starts_with($req->url(), 'https://ploi.io/api/servers')
                && $req->header('Authorization')[0] === 'Bearer ploi_test_token'
                && $req->header('Accept')[0] === 'application/json';
        });
    }

    public function test_post_sends_json_body(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/9/keys' => Http::response(['data' => ['id' => 42]], 201),
        ]);

        $client = new PloiClient($this->makeCredential());
        $client->post('/servers/9/keys', ['name' => 'dply-migrate', 'key' => 'ssh-ed25519 AAA...']);

        Http::assertSent(function (Request $req): bool {
            return $req->method() === 'POST'
                && $req->url() === 'https://ploi.io/api/servers/9/keys'
                && $req['name'] === 'dply-migrate'
                && $req['key'] === 'ssh-ed25519 AAA...';
        });
    }

    public function test_delete_targets_resource(): void
    {
        Http::fake([
            'https://ploi.io/api/servers/9/keys/42' => Http::response('', 204),
        ]);

        $client = new PloiClient($this->makeCredential());
        $client->delete('/servers/9/keys/42');

        Http::assertSent(fn (Request $req): bool => $req->method() === 'DELETE'
            && $req->url() === 'https://ploi.io/api/servers/9/keys/42');
    }

    public function test_assert_success_throws_on_non_2xx(): void
    {
        $client = new PloiClient($this->makeCredential());
        Http::fake([
            'https://ploi.io/api/servers' => Http::response(['message' => 'Unauthenticated.'], 401),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 401/');
        $client->assertSuccess($client->get('/servers'), 'list servers');
    }

    public function test_constructor_rejects_blank_token(): void
    {
        $credential = ProviderCredential::factory()->create([
            'provider' => 'ploi',
            'credentials' => ['api_token' => ''],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        new PloiClient($credential);
    }
}
