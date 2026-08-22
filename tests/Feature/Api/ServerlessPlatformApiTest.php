<?php

namespace Tests\Feature\Api\ServerlessPlatformApiTest;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * @return array{0: Site, 1: string}
 */
function functionSiteWithToken(array $abilities = ['serverless.read', 'serverless.invoke']): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'meta' => [
            'host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            'digitalocean_functions' => [
                'api_host' => 'https://faas.example.test',
                'access_key' => 'key-id:key-secret',
                'namespace' => 'fn-namespace',
            ],
        ],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => Site::STATUS_FUNCTIONS_ACTIVE,
        'meta' => [
            'runtime_profile' => 'digitalocean_functions_web',
            'serverless' => ['action_name' => 'checkout'],
        ],
    ]);

    ['plaintext' => $plaintext] = ApiToken::createToken($user, $organization, 'test', null, $abilities);

    return [$site, $plaintext];
}

it('reports the deployed action and namespace inventory', function () {
    [$site, $token] = functionSiteWithToken();

    Http::fake([
        '*/actions/checkout' => Http::response([
            'name' => 'checkout',
            'version' => '0.0.7',
            'publish' => false,
            'exec' => ['kind' => 'php:8.4', 'main' => 'main', 'binary' => true, 'code' => str_repeat('a', 4000)],
            'limits' => ['memory' => 512, 'timeout' => 30000, 'concurrency' => 5, 'logs' => 10],
            'annotations' => [['key' => 'web-export', 'value' => true]],
        ], 200),
        '*/actions*' => Http::response([['name' => 'checkout'], ['name' => 'worker']], 200),
        '*/packages*' => Http::response([], 200),
        '*/triggers*' => Http::response([['name' => 'dply-hourly']], 200),
        '*/rules*' => Http::response([['name' => 'dply-hourly-rule']], 200),
    ]);

    $this->withToken($token)
        ->getJson("/api/v1/serverless/sites/{$site->id}/platform")
        ->assertOk()
        ->assertJsonPath('data.action_name', 'checkout')
        ->assertJsonPath('data.action.runtime', 'php:8.4')
        ->assertJsonPath('data.action.memory_mb', 512)
        ->assertJsonPath('data.action.web_export', true)
        // 4000 base64 chars ≈ 3000 decoded bytes.
        ->assertJsonPath('data.action.code_bytes', 3000)
        ->assertJsonPath('data.namespace.actions', ['checkout', 'worker'])
        ->assertJsonPath('data.namespace.triggers', ['dply-hourly'])
        ->assertJsonPath('data.error', null);
});

it('reports the host error instead of pretending the action is missing', function () {
    [$site, $token] = functionSiteWithToken();

    Http::fake(['*' => Http::response(['error' => 'namespace unauthorized'], 401)]);

    $this->withToken($token)
        ->getJson("/api/v1/serverless/sites/{$site->id}/platform")
        ->assertOk()
        ->assertJsonPath('data.action', null)
        ->assertJsonPath('data.namespace.actions', []);
});

it('sends a test request and records it as an invocation', function () {
    [$site, $token] = functionSiteWithToken();

    Http::fake(['*' => Http::response(['response' => ['result' => ['body' => 'pong', 'statusCode' => 200]]], 200)]);

    $response = $this->withToken($token)->postJson("/api/v1/serverless/sites/{$site->id}/invoke", [
        'method' => 'POST',
        'path' => '/health',
        'body' => '{"ping":1}',
        'headers' => ['X-Token' => 'abc'],
    ]);

    $response->assertOk()->assertJsonStructure(['data' => ['id', 'ok', 'success', 'status_code', 'duration_ms']]);

    $this->assertDatabaseHas('function_invocations', [
        'site_id' => $site->id,
        'source' => 'test',
    ]);
});

it('needs serverless.invoke to fire a request', function () {
    [$site, $readOnly] = functionSiteWithToken(['serverless.read']);

    Http::fake(['*' => Http::response([], 200)]);

    $this->withToken($readOnly)
        ->getJson("/api/v1/serverless/sites/{$site->id}/platform")
        ->assertOk();

    $this->withToken($readOnly)
        ->postJson("/api/v1/serverless/sites/{$site->id}/invoke", [])
        ->assertForbidden();
});

it('404s for a site that is not a function', function () {
    [, $token] = functionSiteWithToken();
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id]);
    $vmSite = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ]);

    $this->withToken($token)
        ->getJson("/api/v1/serverless/sites/{$vmSite->id}/platform")
        ->assertNotFound();
});

it('reports the stored key id and whether the host accepts it', function () {
    [$site, $token] = functionSiteWithToken();

    Http::fake(['*' => Http::response([['name' => 'checkout']], 200)]);

    $this->withToken($token)
        ->getJson("/api/v1/serverless/sites/{$site->id}/credentials")
        ->assertOk()
        ->assertJsonPath('data.namespace', 'fn-namespace')
        // Only the id half — the secret is the credential.
        ->assertJsonPath('data.key_id', 'key-id')
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.actions', 1);
});

it('stores a rotated key once the host accepts it', function () {
    [$site, $token] = functionSiteWithToken(['serverless.read', 'serverless.write']);

    Http::fake(['*' => Http::response([['name' => 'checkout']], 200)]);

    $this->withToken($token)
        ->putJson("/api/v1/serverless/sites/{$site->id}/credentials", ['access_key' => 'new-id:new-secret'])
        ->assertOk()
        ->assertJsonPath('data.key_id', 'new-id');

    expect($site->server->fresh()->meta['digitalocean_functions']['access_key'])->toBe('new-id:new-secret');
});

it('keeps the old key when the host rejects the new one', function () {
    [$site, $token] = functionSiteWithToken(['serverless.read', 'serverless.write']);

    Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

    $this->withToken($token)
        ->putJson("/api/v1/serverless/sites/{$site->id}/credentials", ['access_key' => 'bad-id:bad-secret'])
        ->assertStatus(422);

    expect($site->server->fresh()->meta['digitalocean_functions']['access_key'])->toBe('key-id:key-secret');
});

it('rejects a key that is not id:secret, and needs serverless.write', function () {
    [$site, $writeToken] = functionSiteWithToken(['serverless.read', 'serverless.write']);
    [$readSite, $readToken] = functionSiteWithToken(['serverless.read']);

    Http::fake(['*' => Http::response([], 200)]);

    $this->withToken($writeToken)
        ->putJson("/api/v1/serverless/sites/{$site->id}/credentials", ['access_key' => 'no-colon'])
        ->assertStatus(422);

    $this->withToken($readToken)
        ->putJson("/api/v1/serverless/sites/{$readSite->id}/credentials", ['access_key' => 'a:b'])
        ->assertForbidden();
});
