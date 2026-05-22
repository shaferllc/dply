<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless;

use App\Models\FunctionInvocation;
use App\Models\Site;
use App\Services\Serverless\FunctionStatsRangeQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FunctionStatsRangeQueryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function invocation(Site $site, array $attrs): void
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

    public function test_summary_aggregates_invocations_errors_duration_and_cold(): void
    {
        $site = Site::factory()->create();
        $this->invocation($site, ['duration_ms' => 100, 'source' => 'tick']);
        $this->invocation($site, ['duration_ms' => 200, 'source' => 'test']);
        $this->invocation($site, ['duration_ms' => 300, 'source' => 'web', 'cold' => true]);
        $this->invocation($site, ['duration_ms' => 400, 'source' => 'web', 'success' => false]);

        $stats = (new FunctionStatsRangeQuery)->forSite($site, '24h');
        $summary = $stats['summary'];

        $this->assertSame(4, $summary['invocations']);
        $this->assertSame(1, $summary['errors']);
        $this->assertSame(25, $summary['error_rate']);
        $this->assertSame(250, $summary['avg_duration']);
        $this->assertSame(400, $summary['p95_duration']);
        $this->assertSame(1, $summary['cold']);
        $this->assertSame(25, $summary['cold_rate']);
        $this->assertSame(['tick' => 1, 'test' => 1, 'web' => 2], $summary['by_source']);
    }

    public function test_it_excludes_invocations_outside_the_window(): void
    {
        $site = Site::factory()->create();
        $this->invocation($site, ['created_at' => now()->subMinutes(10)]);
        $this->invocation($site, ['created_at' => now()->subDays(3)]); // outside 24h

        $stats = (new FunctionStatsRangeQuery)->forSite($site, '24h');

        $this->assertSame(1, $stats['summary']['invocations']);
    }

    public function test_it_returns_a_continuous_bucketed_series(): void
    {
        $site = Site::factory()->create();
        $this->invocation($site, ['created_at' => now()->subMinutes(5)]);

        $stats = (new FunctionStatsRangeQuery)->forSite($site, '1h');

        // 1h window in 5-minute buckets — a point for every bucket.
        $this->assertNotEmpty($stats['series']['invocations']);
        $this->assertSame(
            FunctionStatsRangeQuery::RANGES['1h'],
            $stats['bucket_seconds'],
        );
        foreach ($stats['series']['invocations'] as $point) {
            $this->assertArrayHasKey('at', $point);
            $this->assertArrayHasKey('avg', $point);
        }
    }

    public function test_an_unknown_range_falls_back_to_the_default(): void
    {
        $site = Site::factory()->create();

        $stats = (new FunctionStatsRangeQuery)->forSite($site, 'bogus');

        $this->assertSame(FunctionStatsRangeQuery::defaultRange(), $stats['range']);
    }
}
