<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Sites;

use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\SiteSettingsViewData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('edge deploys section skips usage analytics queries', function () {
    [$server, $site] = makeEdgeSiteForViewData();

    EdgeUsageSnapshot::query()->create([
        'organization_id' => $site->organization_id,
        'site_id' => $site->id,
        'period_start' => now()->subDay()->toDateString(),
        'period_end' => now()->subDay()->toDateString(),
        'requests' => 500,
        'bytes_egress' => 1024,
        'r2_storage_bytes' => 0,
        'r2_class_a_ops' => 0,
        'r2_class_b_ops' => 0,
        'source' => 'manual',
    ]);

    DB::enableQueryLog();

    $payload = SiteSettingsViewData::for(
        $server,
        $site,
        'edge-deploys',
        null,
        [],
        null,
    );

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($payload['edgeSiteBilling'])->toBeNull()
        ->and($payload['edgeSiteTraffic'])->toBeNull()
        ->and($payload['edgeSiteAccess'])->toBeNull()
        ->and(collect($queries)->contains(fn (array $query): bool => str_contains($query['query'], 'edge_usage_snapshots')))->toBeFalse();
});

test('edge overview section loads billing and traffic snapshots', function () {
    [$server, $site] = makeEdgeSiteForViewData();

    EdgeUsageSnapshot::query()->create([
        'organization_id' => $site->organization_id,
        'site_id' => $site->id,
        'period_start' => now()->subDay()->toDateString(),
        'period_end' => now()->subDay()->toDateString(),
        'requests' => 500,
        'bytes_egress' => 1024,
        'r2_storage_bytes' => 0,
        'r2_class_a_ops' => 0,
        'r2_class_b_ops' => 0,
        'source' => 'manual',
    ]);

    $payload = SiteSettingsViewData::for(
        $server,
        $site,
        'general',
        null,
        [],
        null,
    );

    expect($payload['edgeSiteBilling'])->not->toBeNull()
        ->and($payload['edgeSiteTraffic'])->not->toBeNull()
        ->and($payload['edgeSiteAccess'])->toBeNull();
});

/**
 * @return array{0: Server, 1: Site}
 */
function makeEdgeSiteForViewData(): array
{
    $organization = Organization::factory()->create();
    $server = Server::factory()->for($organization)->create();
    $site = Site::factory()->for($organization)->for($server)->create([
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'main'],
                'build' => ['command' => 'npm run build', 'output_dir' => 'dist'],
            ],
        ],
    ]);

    return [$server, $site];
}
