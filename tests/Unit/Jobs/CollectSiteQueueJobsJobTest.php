<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\CollectSiteQueueJobsJobTest;

use App\Jobs\CollectSiteQueueJobsJob;
use ReflectionMethod;

function extract(string $buffer): array
{
    $job = new CollectSiteQueueJobsJob('01hzzzzzzzzzzzzzzzzzzzzzzz', 'default');
    $method = new ReflectionMethod($job, 'extract');

    return $method->invoke($job, $buffer);
}

test('a fenced payload is read out of noisy box output', function () {
    $buffer = "PHP Warning: something\n"
        .'DPLY_QJ_START{"driver":"database","jobs":["{}"],"truncated":false}DPLY_QJ_END'."\n";

    expect(extract($buffer)['driver'])->toBe('database')
        ->and(extract($buffer)['truncated'])->toBeFalse();
});

test('unreadable output becomes an error the page can show, not a silent empty list', function () {
    // An empty list and a failed read look identical to a user; only one of
    // them means "nothing is waiting".
    expect(extract('')['error'] ?? null)->not->toBeNull()
        ->and(extract('DPLY_QJ_STARTnot jsonDPLY_QJ_END')['error'] ?? null)->not->toBeNull();
});

test('the cache key is per site and per queue', function () {
    $a = CollectSiteQueueJobsJob::cacheKey('site-1', 'default');
    $b = CollectSiteQueueJobsJob::cacheKey('site-1', 'emails');
    $c = CollectSiteQueueJobsJob::cacheKey('site-2', 'default');

    expect($a)->not->toBe($b)
        ->and($a)->not->toBe($c);
});
