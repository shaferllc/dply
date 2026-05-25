<?php

declare(strict_types=1);

namespace Tests\Feature\Console\EdgeInfraBootstrapOrgCommandTest;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Support\Edge\EdgeOrgCredentialConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('bootstrap org command creates bucket and kv metadata', function () {
    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/user/tokens/verify')) {
            return Http::response(['success' => true, 'result' => ['status' => 'active']], 200);
        }

        if (str_contains($url, '/accounts') && $request->method() === 'GET' && ! str_contains($url, '/storage/') && ! str_contains($url, '/r2/')) {
            return Http::response(['success' => true, 'result' => [['id' => 'acct-org']]], 200);
        }

        if (str_contains($url, '/storage/kv/namespaces') && $request->method() === 'GET') {
            return Http::response(['success' => true, 'result' => []], 200);
        }

        if (str_contains($url, '/storage/kv/namespaces') && $request->method() === 'POST') {
            $body = json_decode($request->body(), true);
            $title = is_array($body) ? (string) ($body['title'] ?? '') : '';
            $id = $title === 'dply-edge-cache' ? 'cache-kv-new' : 'kv-new';

            return Http::response(['success' => true, 'result' => ['id' => $id, 'title' => $title]], 200);
        }

        if (str_contains($url, '/r2/buckets') && $request->method() === 'GET') {
            return Http::response(['success' => true, 'result' => ['buckets' => []]], 200);
        }

        if (str_contains($url, '/r2/buckets') && $request->method() === 'POST') {
            return Http::response(['success' => true, 'result' => ['name' => 'dply-edge-test']], 200);
        }

        return Http::response(['success' => true, 'result' => []], 200);
    });

    $credential = cloudflareCredential();

    $this->artisan('dply:edge:bootstrap-org', [
        'credential' => $credential->id,
        '--account-id' => 'acct-org',
        '--bucket' => 'dply-edge-test',
        '--zone-name' => 'example.com',
        '--r2-access-key' => 'access-key',
        '--r2-secret' => 'secret-key',
    ])->assertSuccessful();

    $edge = EdgeOrgCredentialConfig::read($credential->fresh());
    expect($edge['account_id'] ?? null)->toBe('acct-org')
        ->and($edge['r2_bucket'] ?? null)->toBe('dply-edge-test')
        ->and($edge['worker_zone_name'] ?? null)->toBe('example.com')
        ->and($edge['kv_namespace_id'] ?? null)->toBe('kv-new')
        ->and($edge['cache_kv_namespace_id'] ?? null)->toBe('cache-kv-new')
        ->and($edge['r2_access_key'] ?? null)->toBe('access-key');
});

function cloudflareCredential(): ProviderCredential
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    return ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'cloudflare',
        'credentials' => ['api_token' => 'cf-token'],
    ]);
}
