<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Edge;

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Services\EdgeCachePurger;
use App\Modules\Edge\Services\EdgeDeliveryContextResolver;
use App\Modules\Edge\Support\EdgeDeliveryContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;

uses(RefreshDatabase::class);

test('purge by tag deletes cache entry and tag pointer from edge cache kv', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $site = Site::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'edge_backend' => 'dply_edge',
        'status' => 'edge_active',
    ]);

    $siteId = (string) $site->id;

    $context = new EdgeDeliveryContext(
        backendKey: 'dply_edge',
        accountId: 'acct',
        apiToken: 'token',
        kvNamespaceId: 'host-ns',
        r2Bucket: 'bucket',
        r2AccessKey: 'key',
        r2Secret: 'secret',
        r2Endpoint: 'https://acct.r2.cloudflarestorage.com',
        r2KeyPrefix: 'edge/',
        workerScriptName: 'dply-edge',
        workerZoneName: 'on-dply.site',
        workerRoutes: ['*.on-dply.site/*'],
        diskName: 'edge_r2',
        cacheKvNamespaceId: 'cache-ns',
    );

    $resolver = Mockery::mock(EdgeDeliveryContextResolver::class);
    $resolver->shouldReceive('forSite')->andReturn($context);
    app()->instance(EdgeDeliveryContextResolver::class, $resolver);

    Http::fake(function ($request) use ($siteId) {
        $url = $request->url();
        $tagKey = rawurlencode("edge_cache_tag:{$siteId}:article-42");

        if (str_contains($url, $tagKey) && $request->method() === 'GET') {
            return Http::response("edge_cache:{$siteId}:/api/article", 200);
        }

        if ($request->method() === 'DELETE') {
            return Http::response(['success' => true], 200);
        }

        return Http::response(['success' => false], 404);
    });

    $result = app(EdgeCachePurger::class)->purgeByTag($site, 'article-42');

    expect($result['ok'])->toBeTrue()
        ->and($result['purged_keys'])->toHaveCount(2);
});
