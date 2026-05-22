<?php

declare(strict_types=1);

namespace Tests\Feature\Console\PruneFunctionInvocationsCommandTest;
use App\Models\FunctionInvocation;
use App\Models\Site;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function row(Site $site, string $source, \DateTimeInterface $at): void
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
test('it drops web rows older than seven days', function () {
    $site = Site::factory()->create();
    row($site, 'web', now()->subDays(8));
    row($site, 'web', now()->subDay());

    $this->artisan('serverless:prune-invocations')->assertSuccessful();

    expect(FunctionInvocation::query()->where('source', 'web')->count())->toBe(1);
});
test('it keeps web rows within the per site cap', function () {
    $site = Site::factory()->create();

    // 505 recent web rows — 5 past the 500 cap.
    for ($i = 0; $i < 505; $i++) {
        row($site, 'web', now()->subMinutes($i));
    }

    $this->artisan('serverless:prune-invocations')->assertSuccessful();

    expect(FunctionInvocation::query()->where('source', 'web')->count())->toBe(500);
});
test('it drops tick rows older than thirty days but keeps recent ones', function () {
    $site = Site::factory()->create();
    row($site, 'tick', now()->subDays(31));
    row($site, 'tick', now()->subDays(10));

    $this->artisan('serverless:prune-invocations')->assertSuccessful();

    expect(FunctionInvocation::query()->where('source', 'tick')->count())->toBe(1);
});
