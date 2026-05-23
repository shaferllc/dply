<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Edge\EdgeDeliveryContextResolverTest;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Models\User;
use App\Services\Edge\EdgeDeliveryContextResolver;
use App\Support\Edge\EdgeOrgCredentialConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('platform context for dply_edge backend', function () {
    config([
        'edge.cloudflare.account_id' => 'acct-platform',
        'edge.cloudflare.api_token' => 'token-platform',
        'edge.cloudflare.kv_namespace_id' => 'kv-platform',
        'edge.r2.bucket' => 'platform-bucket',
        'edge.r2.key' => 'key',
        'edge.r2.secret' => 'secret',
        'edge.r2.endpoint' => 'https://acct-platform.r2.cloudflarestorage.com',
    ]);

    $site = makeEdgeSite('dply_edge', null);

    $context = app(EdgeDeliveryContextResolver::class)->forSite($site);

    expect($context->backendKey)->toBe('dply_edge')
        ->and($context->accountId)->toBe('acct-platform')
        ->and($context->diskName)->toBe('edge_r2');
});

test('org context resolves credential disk and ids', function () {
    [$org, $credential] = bootstrappedCredential();

    $site = makeEdgeSite('org_cloudflare', $credential->id, $org);

    $context = app(EdgeDeliveryContextResolver::class)->forSite($site);

    expect($context->backendKey)->toBe('org_cloudflare')
        ->and($context->accountId)->toBe('acct-org')
        ->and($context->kvNamespaceId)->toBe('kv-org')
        ->and($context->diskName)->toBe('edge_r2_org_'.$credential->id);
});

function makeEdgeSite(string $backend, ?string $credentialId, ?Organization $org = null): Site
{
    $org ??= Organization::factory()->create();

    return Site::factory()->create([
        'organization_id' => $org->id,
        'edge_backend' => $backend,
        'edge_provider_credential_id' => $credentialId,
        'meta' => ['edge' => ['routing' => ['hostname' => 'demo.dply.host']]],
    ]);
}

/**
 * @return array{0: Organization, 1: ProviderCredential}
 */
function bootstrappedCredential(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'cloudflare',
        'credentials' => ['api_token' => 'cf-token'],
    ]);

    EdgeOrgCredentialConfig::merge($credential, [
        'account_id' => 'acct-org',
        'kv_namespace_id' => 'kv-org',
        'r2_bucket' => 'dply-edge-org',
        'r2_access_key' => 'access',
        'r2_secret' => 'secret',
        'r2_endpoint' => 'https://acct-org.r2.cloudflarestorage.com',
        'worker_zone_name' => 'example.com',
        'worker_routes' => ['*.example.com/*'],
    ]);

    return [$org, $credential->fresh()];
}
