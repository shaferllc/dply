<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless\FunctionStatsRangeQueryTest;

use App\Modules\Serverless\Models\FunctionInvocation;
use App\Models\Site;
use App\Modules\Serverless\Services\FunctionStatsRangeQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $attrs
 */
function invocation(Site $site, array $attrs): void
{
    FunctionInvocation::query()->create(array_merge([
        'site_id' => $site->id,
        'source' => FunctionInvocation::SOURCE_WEB,
        'task' => null,
        'method' => 'GET',
        'path' => '/',
        'status_code' => 200,
        'success' => true,
        'duration_ms' => 100,
        'cold' => false,
        'log_lines' => [],
        'created_at' => now(),
    ], $attrs));
}
test('summary aggregates invocations errors duration and cold', function () {
    $site = Site::factory()->create();
    invocation($site, ['duration_ms' => 100, 'source' => 'tick']);
    invocation($site, ['duration_ms' => 200, 'source' => 'test']);
    invocation($site, ['duration_ms' => 300, 'source' => 'web', 'cold' => true]);
    invocation($site, ['duration_ms' => 400, 'source' => 'web', 'success' => false]);

    $stats = (new FunctionStatsRangeQuery)->forSite($site, '24h');
    $summary = $stats['summary'];

    expect($summary['invocations'])->toBe(4);
    expect($summary['errors'])->toBe(1);
    expect($summary['error_rate'])->toBe(25);
    expect($summary['avg_duration'])->toBe(250);
    expect($summary['p95_duration'])->toBe(400);
    expect($summary['cold'])->toBe(1);
    expect($summary['cold_rate'])->toBe(25);
    expect($summary['by_source'])->toBe(['tick' => 1, 'test' => 1, 'web' => 2]);
});
test('it excludes invocations outside the window', function () {
    $site = Site::factory()->create();
    invocation($site, ['created_at' => now()->subMinutes(10)]);
    invocation($site, ['created_at' => now()->subDays(3)]);

    // outside 24h
    $stats = (new FunctionStatsRangeQuery)->forSite($site, '24h');

    expect($stats['summary']['invocations'])->toBe(1);
});
test('it returns a continuous bucketed series', function () {
    $site = Site::factory()->create();
    invocation($site, ['created_at' => now()->subMinutes(5)]);

    $stats = (new FunctionStatsRangeQuery)->forSite($site, '1h');

    // 1h window in 5-minute buckets — a point for every bucket.
    expect($stats['series']['invocations'])->not->toBeEmpty();
    expect($stats['bucket_seconds'])->toBe(FunctionStatsRangeQuery::RANGES['1h']);
    foreach ($stats['series']['invocations'] as $point) {
        expect($point)->toHaveKey('at');
        expect($point)->toHaveKey('avg');
    }
});
test('an unknown range falls back to the default', function () {
    $site = Site::factory()->create();

    $stats = (new FunctionStatsRangeQuery)->forSite($site, 'bogus');

    expect($stats['range'])->toBe(FunctionStatsRangeQuery::defaultRange());
});
