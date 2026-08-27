<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\RunSiteQueueCanaryJobTest;

use App\Jobs\RunSiteQueueCanaryJob;
use ReflectionMethod;

function extract(string $buffer): ?array
{
    $job = new RunSiteQueueCanaryJob('c1', '01hzzzzzzzzzzzzzzzzzzzzzzz', 'default');

    return (new ReflectionMethod($job, 'extract'))->invoke($job, $buffer);
}

test('a consumed job reports the round trip', function () {
    $buffer = 'noise DPLY_QC_START{"driver":"redis","consumed":true,"ms":180}DPLY_QC_END trailing';

    expect(extract($buffer)['consumed'])->toBeTrue()
        ->and(extract($buffer)['ms'])->toBe(180);
});

test('a refusal comes back as an error, not as a failed test', function () {
    // sync and a per-process cache are misconfigurations the canary can name.
    // Reporting them as "no worker picked it up" would send someone to debug
    // Supervisor over an env problem.
    $buffer = 'DPLY_QC_START{"driver":"sync","error":"QUEUE_CONNECTION is sync"}DPLY_QC_END';

    expect(extract($buffer)['error'])->toContain('sync')
        ->and(extract($buffer)['consumed'] ?? false)->toBeFalse();
});

test('unparseable output is null so the caller can say the app did not answer', function () {
    expect(extract(''))->toBeNull()
        ->and(extract('DPLY_QC_STARTnot jsonDPLY_QC_END'))->toBeNull();
});
