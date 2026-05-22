<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\FunctionInvocation;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneFunctionInvocationsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function row(Site $site, string $source, \DateTimeInterface $at): void
    {
        FunctionInvocation::query()->create([
            'site_id' => $site->id,
            'source' => $source,
            'task' => $source === 'tick' ? 'schedule' : null,
            'method' => 'GET',
            'path' => '/',
            'status_code' => 200,
            'success' => true,
            'duration_ms' => 10,
            'cold' => false,
            'activation_id' => 'act',
            'log_lines' => [],
            'result_excerpt' => null,
            'created_at' => $at,
        ]);
    }

    public function test_it_drops_web_rows_older_than_seven_days(): void
    {
        $site = Site::factory()->create();
        $this->row($site, 'web', now()->subDays(8));
        $this->row($site, 'web', now()->subDay());

        $this->artisan('serverless:prune-invocations')->assertSuccessful();

        $this->assertSame(1, FunctionInvocation::query()->where('source', 'web')->count());
    }

    public function test_it_keeps_web_rows_within_the_per_site_cap(): void
    {
        $site = Site::factory()->create();
        // 505 recent web rows — 5 past the 500 cap.
        for ($i = 0; $i < 505; $i++) {
            $this->row($site, 'web', now()->subMinutes($i));
        }

        $this->artisan('serverless:prune-invocations')->assertSuccessful();

        $this->assertSame(500, FunctionInvocation::query()->where('source', 'web')->count());
    }

    public function test_it_drops_tick_rows_older_than_thirty_days_but_keeps_recent_ones(): void
    {
        $site = Site::factory()->create();
        $this->row($site, 'tick', now()->subDays(31));
        $this->row($site, 'tick', now()->subDays(10));

        $this->artisan('serverless:prune-invocations')->assertSuccessful();

        $this->assertSame(1, FunctionInvocation::query()->where('source', 'tick')->count());
    }
}
