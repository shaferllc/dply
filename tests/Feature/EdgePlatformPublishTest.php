<?php

declare(strict_types=1);

namespace Tests\Feature\EdgePlatformPublishTest;

use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Edge\CloudflareEdgeDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('platform publish hostname matches live url kv key', function () {
    config([
        'edge.fake.enabled' => false,
        'edge.r2.bucket' => 'dply-edge',
        'edge.r2.key' => 'access',
        'edge.r2.secret' => 'secret',
        'edge.cloudflare.account_id' => 'acct-platform',
        'edge.cloudflare.api_token' => 'cf-token',
        'edge.cloudflare.kv_namespace_id' => 'kv-platform',
    ]);

    Storage::fake('edge_r2');

    Http::fake([
        'api.cloudflare.com/*' => Http::response(['success' => true, 'result' => []], 200),
    ]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();

    $site = Site::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_PROVISIONING,
        'slug' => 'test-site',
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'routing' => [
                    'hostname' => 'test-site.dply.host',
                    'spa_fallback' => true,
                ],
            ],
        ],
    ]);

    $deployment = EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_BUILDING,
        'storage_prefix' => 'edge/org/site/deploy',
        'git_branch' => 'main',
    ]);

    $artifactDir = sys_get_temp_dir().'/edge-artifact-'.uniqid('', true);
    mkdir($artifactDir);
    file_put_contents($artifactDir.'/index.html', '<html>ok</html>');

    try {
        $result = app(CloudflareEdgeDelivery::class)->publishDeployment($deployment, $site, $artifactDir);
    } finally {
        @unlink($artifactDir.'/index.html');
        @rmdir($artifactDir);
    }

    expect($result['live_url'])->toBe('https://test-site.dply.host');

    Http::assertSent(function ($request): bool {
        return $request->method() === 'PUT'
            && str_contains($request->url(), '/values/test-site.dply.host');
    });
});
