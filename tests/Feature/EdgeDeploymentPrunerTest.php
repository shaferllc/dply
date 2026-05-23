<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeDeploymentPrunerTest;

use App\Enums\SiteType;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Services\Edge\EdgeDeploymentPruner;
use App\Support\Edge\FakeEdgeProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['edge.fake.enabled' => true]);
});

test('prune deletes artifacts beyond keep count and nulls storage_prefix', function () {
    [$site, $deployments, $prefixDirs] = scaffoldPrunerScenario(keep: 2, count: 4);

    $pruned = app(EdgeDeploymentPruner::class)->prune($site);

    expect($pruned)->toBe(2);

    foreach ($deployments as $i => $deployment) {
        $deployment->refresh();
        if ($i < 2) {
            expect($deployment->storage_prefix)->not->toBeNull();
            expect($deployment->pruned_at)->toBeNull();
            expect(is_dir($prefixDirs[$i]))->toBeTrue();
        } else {
            expect($deployment->storage_prefix)->toBeNull();
            expect($deployment->pruned_at)->not->toBeNull();
            expect(is_dir($prefixDirs[$i]))->toBeFalse();
        }
    }
});

test('prune is a no-op when count is within keep limit', function () {
    [$site] = scaffoldPrunerScenario(keep: 5, count: 3);

    expect(app(EdgeDeploymentPruner::class)->prune($site))->toBe(0);
});

test('prune respects site-specific keep override (one)', function () {
    config(['edge.retention.default_keep' => 99]);
    [$site, $deployments] = scaffoldPrunerScenario(keep: 1, count: 3);

    expect(app(EdgeDeploymentPruner::class)->prune($site))->toBe(2);
});

/**
 * Create a site with N SUPERSEDED deployments and matching fake-edge artifact dirs.
 *
 * @return array{0: Site, 1: list<EdgeDeployment>, 2: list<string>}
 */
function scaffoldPrunerScenario(int $keep, int $count): array
{
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'releases_to_keep' => $keep,
    ]);

    $deployments = [];
    $dirs = [];
    $root = rtrim(FakeEdgeProvision::storageRoot(), '/');

    // Newest first so index 0 is the most recent (matches pruner's order).
    for ($i = 0; $i < $count; $i++) {
        $prefix = 'edge/'.$org->id.'/'.$site->id.'/'.sprintf('01PRUNE%020d', $i);
        $deployment = EdgeDeployment::query()->create([
            'site_id' => $site->id,
            'organization_id' => $org->id,
            'status' => EdgeDeployment::STATUS_SUPERSEDED,
            'storage_prefix' => $prefix,
            'cf_kv_version' => 1,
            'published_at' => now()->subMinutes($i),
        ]);
        $dir = $root.'/'.$prefix;
        File::ensureDirectoryExists($dir);
        File::put($dir.'/index.html', '<!doctype html>'.$i);
        $deployments[] = $deployment;
        $dirs[] = $dir;
    }

    return [$site->refresh(), $deployments, $dirs];
}
