<?php

declare(strict_types=1);

namespace Tests\Unit\SiteServerlessLimitsTest;
use App\Models\Site;
function siteWithLimits(mixed $limits): Site
{
    $site = new Site;
    $site->meta = ['serverless' => ['limits' => $limits]];

    return $site;
}
test('it returns platform defaults when no limits are stored', function () {
    $site = new Site;
    $site->meta = [];

    expect($site->serverlessLimits())->toBe([
        'memory' => Site::SERVERLESS_DEFAULT_MEMORY_MB,
        'timeout' => Site::SERVERLESS_DEFAULT_TIMEOUT_MS,
        'concurrency' => Site::SERVERLESS_DEFAULT_CONCURRENCY,
    ]);
});
test('it passes through valid stored limits', function () {
    $limits = siteWithLimits(['memory' => 1024, 'timeout' => 120000, 'concurrency' => 8])
        ->serverlessLimits();

    expect($limits)->toBe(['memory' => 1024, 'timeout' => 120000, 'concurrency' => 8]);
});
test('it falls back to default memory for an unsupported value', function () {
    expect(siteWithLimits(['memory' => 999])->serverlessLimits()['memory'])->toBe(Site::SERVERLESS_DEFAULT_MEMORY_MB);
});
test('it clamps timeout into the allowed range', function () {
    expect(siteWithLimits(['timeout' => 9_000_000])->serverlessLimits()['timeout'])->toBe(Site::SERVERLESS_MAX_TIMEOUT_MS);
    expect(siteWithLimits(['timeout' => 1])->serverlessLimits()['timeout'])->toBe(Site::SERVERLESS_MIN_TIMEOUT_MS);
});
test('it clamps concurrency into the allowed range', function () {
    expect(siteWithLimits(['concurrency' => 999])->serverlessLimits()['concurrency'])->toBe(Site::SERVERLESS_MAX_CONCURRENCY);
    expect(siteWithLimits(['concurrency' => 0])->serverlessLimits()['concurrency'])->toBe(1);
});
