<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\Sites\QueueWorkerCommandTest;

use App\Livewire\Sites\WorkspaceQueue;
use App\Support\Sites\QueueWorkerClassifier;

/**
 * The command the create form builds must be one the classifier recognises and
 * the sweep can read a queue name out of. If those three disagree, a worker you
 * just created never appears on the page that created it.
 */
function builtCommand(string $queue, int $sleep = 3, int $timeout = 60, int $tries = 3, int $memory = 128): string
{
    return sprintf(
        "php artisan queue:work --queue='%s' --sleep=%d --timeout=%d --tries=%d --memory=%d --max-time=3600",
        $queue,
        $sleep,
        $timeout,
        $tries,
        $memory,
    );
}

test('a worker created here is classified as a queue worker and keeps its queue name', function () {
    $command = builtCommand('emails');

    expect(QueueWorkerClassifier::isQueueWorker($command))->toBeTrue()
        ->and(QueueWorkerClassifier::queueNameFrom($command))->toBe('emails');
});

test('the built command matches the shape already running on real sites', function () {
    // Byte-identical to the outbidpixels program, so the page creates what the
    // fleet already runs rather than a second dialect.
    expect(builtCommand('default'))
        ->toBe("php artisan queue:work --queue='default' --sleep=3 --timeout=60 --tries=3 --memory=128 --max-time=3600");
});

test('a multi-queue worker survives the round trip whole', function () {
    expect(QueueWorkerClassifier::queueNameFrom(builtCommand('high,default')))->toBe('high,default');
});

/**
 * The real builder, so the test cannot drift from the code the way a
 * reimplemented sprintf() would.
 */
function build(string $queue, array $options): string
{
    $method = new \ReflectionMethod(WorkspaceQueue::class, 'buildWorkerCommand');

    return $method->invoke(new WorkspaceQueue, $queue, $options + [
        'new_connection' => '',
        'new_sleep' => 3,
        'new_timeout' => 60,
        'new_tries' => 3,
        'new_memory' => 128,
        'new_max_time' => 3600,
    ]);
}

test('defaults still produce the exact command the fleet already runs', function () {
    expect(build('default', []))->toBe(builtCommand('default'));
});

test('optional flags are omitted when blank, not sent as zero', function () {
    // --backoff=0 is not the same as no backoff to every driver, and a command
    // line carrying only what you set is one you can read back.
    $command = build('default', ['new_backoff' => '', 'new_max_jobs' => '', 'new_rest' => '']);

    expect($command)->not->toContain('--backoff')
        ->and($command)->not->toContain('--max-jobs')
        ->and($command)->not->toContain('--rest');
});

test('a connection is positional and precedes the flags', function () {
    // `queue:work redis --queue=...` — after the flags it is not parsed as the
    // connection at all.
    expect(build('high,default', ['new_connection' => 'redis']))
        ->toStartWith("php artisan queue:work 'redis' --queue='high,default'");
});

test('max-time 0 means never exit, and drops the flag rather than sending zero', function () {
    expect(build('default', ['new_max_time' => 0]))->not->toContain('--max-time');
});

test('every generated command is still recognised as a queue worker', function () {
    // If the builder and the classifier disagree, a worker you just created
    // never appears on the page that created it.
    foreach ([[], ['new_connection' => 'redis'], ['new_max_jobs' => '500'], ['new_max_time' => 0]] as $options) {
        expect(QueueWorkerClassifier::isQueueWorker(build('emails', $options)))->toBeTrue()
            ->and(QueueWorkerClassifier::queueNameFrom(build('emails', $options)))->toBe('emails');
    }
});
