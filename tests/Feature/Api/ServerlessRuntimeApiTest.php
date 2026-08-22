<?php

namespace Tests\Feature\Api\ServerlessRuntimeApiTest;

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
function runtimeSiteWithToken(array $abilities = ['serverless.read', 'serverless.write'], array $serverless = []): array
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
            'serverless' => array_merge(['action_name' => 'checkout'], $serverless),
        ],
    ]);

    ['plaintext' => $plaintext] = ApiToken::createToken($user, $organization, 'test', null, $abilities);

    return [$site, $plaintext];
}

beforeEach(function () {
    // Every HTTP-config write pushes to the live action; the host answering
    // "ok" is the uninteresting half of these tests.
    Http::fake(['*' => Http::response(['ok' => true], 200)]);
});

it('reports limits, http exposure, parameters, and toggles', function () {
    [$site, $token] = runtimeSiteWithToken(serverless: [
        'limits' => ['memory' => 512, 'timeout' => 60000, 'concurrency' => 2, 'logs' => 128],
        'deployed_limits' => ['memory' => 256, 'timeout' => 60000, 'concurrency' => 2, 'logs' => 128],
        'parameters' => ['STRIPE_MODE' => 'live'],
        'keep_warm' => true,
    ]);

    $this->withToken($token)
        ->getJson("/api/v1/serverless/sites/{$site->id}/runtime")
        ->assertOk()
        ->assertJsonPath('data.limits.memory_mb', 512)
        ->assertJsonPath('data.limits.concurrency', 2)
        // Saved memory differs from what is deployed — the tab's redeploy hint.
        ->assertJsonPath('data.limits.pending_redeploy', true)
        ->assertJsonPath('data.parameters.STRIPE_MODE', 'live')
        ->assertJsonPath('data.keep_warm', true)
        ->assertJsonPath('data.maintenance', false);
});

it('never returns the log-forwarding token, only whether one is held', function () {
    [$site, $token] = runtimeSiteWithToken(serverless: [
        'log_forwarding' => ['provider' => 'datadog', 'token' => 'super-secret', 'endpoint' => ''],
    ]);

    $response = $this->withToken($token)
        ->getJson("/api/v1/serverless/sites/{$site->id}/runtime")
        ->assertOk()
        ->assertJsonPath('data.log_forwarding.provider', 'datadog')
        ->assertJsonPath('data.log_forwarding.token_set', true);

    expect($response->getContent())->not->toContain('super-secret');
});

it('patches only the fields it is given', function () {
    [$site, $token] = runtimeSiteWithToken(serverless: [
        'limits' => ['memory' => 512, 'timeout' => 60000, 'concurrency' => 2, 'logs' => 128],
    ]);

    $this->withToken($token)
        ->patchJson("/api/v1/serverless/sites/{$site->id}/runtime", ['memory_mb' => 1024])
        ->assertOk()
        ->assertJsonPath('data.limits.memory_mb', 1024)
        // Untouched limits keep their stored values rather than resetting.
        ->assertJsonPath('data.limits.timeout_ms', 60000)
        ->assertJsonPath('data.limits.concurrency', 2)
        ->assertJsonPath('data.limits.logs_kb', 128);

    expect($site->fresh()->meta['serverless']['limits']['memory'])->toBe(1024);
});

it('mints an endpoint secret when the function is first secured, and rotates on request', function () {
    [$site, $token] = runtimeSiteWithToken();

    $this->withToken($token)
        ->patchJson("/api/v1/serverless/sites/{$site->id}/runtime", ['secured' => true])
        ->assertOk()
        ->assertJsonPath('data.http.secured', true);

    $first = $site->fresh()->meta['serverless']['web']['auth_secret'];
    expect($first)->toBeString()->not->toBe('');

    // A later save keeps the same secret — rotation is explicit.
    $this->withToken($token)
        ->patchJson("/api/v1/serverless/sites/{$site->id}/runtime", ['web_mode' => 'web'])
        ->assertOk();

    expect($site->fresh()->meta['serverless']['web']['auth_secret'])->toBe($first);

    $this->withToken($token)
        ->postJson("/api/v1/serverless/sites/{$site->id}/runtime/rotate-secret")
        ->assertOk()
        ->assertJsonPath('data.rotated', true);

    expect($site->fresh()->meta['serverless']['web']['auth_secret'])->not->toBe($first);
});

it('refuses to rotate a secret on an unsecured endpoint', function () {
    [$site, $token] = runtimeSiteWithToken();

    $this->withToken($token)
        ->postJson("/api/v1/serverless/sites/{$site->id}/runtime/rotate-secret")
        ->assertStatus(422);
});

it('keeps the stored log token when a patch does not carry one', function () {
    [$site, $token] = runtimeSiteWithToken(serverless: [
        'log_forwarding' => ['provider' => 'datadog', 'token' => 'keep-me', 'endpoint' => ''],
    ]);

    $this->withToken($token)
        ->patchJson("/api/v1/serverless/sites/{$site->id}/runtime", ['secured' => false])
        ->assertOk();

    expect($site->fresh()->meta['serverless']['log_forwarding']['token'])->toBe('keep-me');
});

it('replaces the parameter map and validates names', function () {
    [$site, $token] = runtimeSiteWithToken(serverless: ['parameters' => ['OLD' => '1']]);

    $this->withToken($token)
        ->patchJson("/api/v1/serverless/sites/{$site->id}/runtime", ['parameters' => ['NEW' => '2']])
        ->assertOk()
        ->assertJsonPath('data.parameters', ['NEW' => '2']);

    $this->withToken($token)
        ->patchJson("/api/v1/serverless/sites/{$site->id}/runtime", ['parameters' => ['bad name' => '2']])
        ->assertStatus(422);
});

it('validates limits against the platform ceilings', function () {
    [$site, $token] = runtimeSiteWithToken();

    $this->withToken($token)
        ->patchJson("/api/v1/serverless/sites/{$site->id}/runtime", ['memory_mb' => 999])
        ->assertStatus(422);

    $this->withToken($token)
        ->patchJson("/api/v1/serverless/sites/{$site->id}/runtime", ['concurrency' => 500])
        ->assertStatus(422);

    $this->withToken($token)
        ->patchJson("/api/v1/serverless/sites/{$site->id}/runtime", ['web_mode' => 'sideways'])
        ->assertStatus(422);
});

it('rejects maintenance for a function that is not Laravel', function () {
    [$site, $token] = runtimeSiteWithToken();

    $this->withToken($token)
        ->patchJson("/api/v1/serverless/sites/{$site->id}/runtime", ['maintenance' => true])
        ->assertStatus(422);
});

it('needs serverless.write to change anything', function () {
    [$site, $readToken] = runtimeSiteWithToken(['serverless.read']);

    $this->withToken($readToken)
        ->getJson("/api/v1/serverless/sites/{$site->id}/runtime")
        ->assertOk();

    $this->withToken($readToken)
        ->patchJson("/api/v1/serverless/sites/{$site->id}/runtime", ['memory_mb' => 256])
        ->assertForbidden();

    $this->withToken($readToken)
        ->postJson("/api/v1/serverless/sites/{$site->id}/runtime/rotate-secret")
        ->assertForbidden();
});
