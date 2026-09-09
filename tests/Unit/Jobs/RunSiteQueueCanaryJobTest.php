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

test('the canary runs from a real file, never through eval', function () {
    // SerializableClosure reads a closure's SOURCE FILE to serialise it, so a
    // script delivered via `php -r "eval(...)"` cannot dispatch one:
    // "file_get_contents(Command line code(1) : eval()'d code)". Every other
    // remote reader in dply uses eval safely because none serialises a closure.
    // This test is the only thing standing between that difference and a
    // canary that fails on every site.
    $job = new RunSiteQueueCanaryJob('c1', '01hzzzzzzzzzzzzzzzzzzzzzzz', 'default');
    $bash = (new ReflectionMethod($job, 'buildScript'))->invoke($job, '/srv/app', 'cGF5bG9hZA==');

    expect($bash)->not->toContain('eval(')
        ->and($bash)->toContain('cat > ')
        ->and($bash)->toContain('/tmp/dply-canary-c1.php')
        // The script must be a real PHP file, so it needs its own opening tag.
        ->and($bash)->toContain('<?php')
        // And it must clean up after itself.
        ->and($bash)->toContain('rm -f');
});

test('the script is removed even when the run fails', function () {
    $job = new RunSiteQueueCanaryJob('c2', '01hzzzzzzzzzzzzzzzzzzzzzzz', 'default');
    $bash = (new ReflectionMethod($job, 'buildScript'))->invoke($job, '/srv/app', 'x');
    $lines = explode("\n", $bash);

    // `|| true` on the run line, so a non-zero exit cannot skip the rm under
    // runInlineBash's `set -e`.
    expect(end($lines))->toContain('rm -f')
        ->and($bash)->toContain('2>&1 || true');
});
