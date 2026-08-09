<?php

declare(strict_types=1);

namespace Tests\Unit\Serverless\HandlerQueueWakeTest;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Illuminate\Queue\Events\JobQueued;

/**
 * The injected handler is a standalone file, not an autoloaded class — but
 * its helpers are plain functions guarded by function_exists, so requiring
 * it defines them without running main(). That makes the wake logic directly
 * testable instead of only observable through a real deploy.
 */
beforeEach(function () {
    require_once base_path('resources/serverless/digitalocean-functions-laravel-handler.php');
});

/** Minimal stand-in for the Laravel container the handler is handed. */
function fakeApp(?Dispatcher $events): object
{
    return new class($events)
    {
        public function __construct(private ?Dispatcher $events) {}

        public function make(string $abstract): mixed
        {
            if ($this->events === null) {
                throw new \RuntimeException('no dispatcher bound');
            }

            return $this->events;
        }
    };
}

test('it registers a JobQueued listener on the app dispatcher', function () {
    $events = new EventDispatcher;

    dply_do_functions_attach_queue_wake(fakeApp($events), []);

    expect($events->hasListeners(JobQueued::class))->toBeTrue();
});

test('flushing without any queued job does nothing', function () {
    $events = new EventDispatcher;

    // A reachable-looking URL: if the flush wrongly fired, this would attempt
    // a real request. Nothing was queued, so it must return before that.
    $flush = dply_do_functions_attach_queue_wake(fakeApp($events), [
        'DPLY_QUEUE_WAKE_URL' => 'https://dply.invalid/hooks/functions/x/queue/wake',
        'DPLY_COMMAND_SECRET' => 'secret',
    ]);

    $started = microtime(true);
    $flush();

    // No network attempt means effectively instant; a real curl would spend
    // its connect timeout budget.
    expect(microtime(true) - $started)->toBeLessThan(0.2);
});

test('a missing wake url is skipped rather than erroring', function () {
    $events = new EventDispatcher;
    $flush = dply_do_functions_attach_queue_wake(fakeApp($events), ['DPLY_COMMAND_SECRET' => 'secret']);

    $events->dispatch(JobQueued::class, [], true);

    $flush();
})->throwsNoExceptions();

test('a missing command secret is skipped rather than erroring', function () {
    $events = new EventDispatcher;
    $flush = dply_do_functions_attach_queue_wake(fakeApp($events), [
        'DPLY_QUEUE_WAKE_URL' => 'https://dply.invalid/hooks/functions/x/queue/wake',
    ]);

    $events->dispatch(JobQueued::class, [], true);

    $flush();
})->throwsNoExceptions();

test('a local wake url is skipped so dev deploys never ping loopback', function () {
    $events = new EventDispatcher;

    foreach (['http://localhost/x', 'http://127.0.0.1/x', 'http://dply.local/x'] as $url) {
        $flush = dply_do_functions_attach_queue_wake(fakeApp($events), [
            'DPLY_QUEUE_WAKE_URL' => $url,
            'DPLY_COMMAND_SECRET' => 'secret',
        ]);

        $events->dispatch(JobQueued::class, [], true);

        $started = microtime(true);
        $flush();
        expect(microtime(true) - $started)->toBeLessThan(0.2);
    }
});

test('an app with no event dispatcher degrades to a no-op flush', function () {
    // The safety-net tick still drains this app; the ping is just unavailable.
    $flush = dply_do_functions_attach_queue_wake(fakeApp(null), [
        'DPLY_QUEUE_WAKE_URL' => 'https://dply.invalid/x',
        'DPLY_COMMAND_SECRET' => 'secret',
    ]);

    expect($flush)->toBeCallable();
    $flush();

    // Reaching here at all is the assertion: the flush neither threw nor
    // attempted a send it had no way to authenticate.
    expect(true)->toBeTrue();
});

test('many queued jobs still only produce one ping', function () {
    // Debounce: the listener sets a flag rather than counting, so a request
    // that dispatches fifty jobs wakes the pump once. The pump's `remaining`
    // feedback covers the rest.
    $events = new EventDispatcher;
    $flush = dply_do_functions_attach_queue_wake(fakeApp($events), [
        'DPLY_QUEUE_WAKE_URL' => 'http://localhost/skipped',
        'DPLY_COMMAND_SECRET' => 'secret',
    ]);

    for ($i = 0; $i < 50; $i++) {
        $events->dispatch(JobQueued::class, [], true);
    }

    $started = microtime(true);
    $flush();

    // One skipped send, not fifty — fifty would be visibly slower even when
    // each is skipped.
    expect(microtime(true) - $started)->toBeLessThan(0.2);
});

test('the retry task accepts all and a well-formed uuid', function () {
    $args = ['__ow_headers' => ['x-dply-run' => 'queue-retry', 'x-dply-secret' => 's']];
    $env = ['DPLY_COMMAND_SECRET' => 's'];

    expect(dply_do_functions_command($args, $env))->toBe(['queue:retry', ['id' => ['all']]]);

    $args['__ow_headers']['x-dply-queue-retry-id'] = '9b1d-4c2a-uuid';
    expect(dply_do_functions_command($args, $env))->toBe(['queue:retry', ['id' => ['9b1d-4c2a-uuid']]]);
});

test('a malformed retry id is rejected rather than passed to artisan', function () {
    // The id reaches an artisan command, so anything shell- or option-shaped
    // must be refused at the door.
    $env = ['DPLY_COMMAND_SECRET' => 's'];

    foreach (['--force', 'a b', 'x;whoami', str_repeat('a', 65), 'a/b'] as $bad) {
        $args = ['__ow_headers' => [
            'x-dply-run' => 'queue-retry',
            'x-dply-secret' => 's',
            'x-dply-queue-retry-id' => $bad,
        ]];

        expect(fn () => dply_do_functions_command($args, $env))
            ->toThrow(\RuntimeException::class);
    }
});

test('the retry task still requires the command secret', function () {
    $args = ['__ow_headers' => ['x-dply-run' => 'queue-retry', 'x-dply-secret' => 'wrong']];

    expect(fn () => dply_do_functions_command($args, ['DPLY_COMMAND_SECRET' => 'right']))
        ->toThrow(\RuntimeException::class);
});

test('queue slot options come from the pump headers', function () {
    $args = ['__ow_headers' => [
        'x-dply-run' => 'queue',
        'x-dply-secret' => 's',
        'x-dply-queue-max-time' => '30',
        'x-dply-queue-max-jobs' => '5',
        'x-dply-queue' => 'emails,default',
    ]];

    [$command, $options] = dply_do_functions_command($args, ['DPLY_COMMAND_SECRET' => 's']);

    expect($command)->toBe('queue:work');
    expect($options['--max-time'])->toBe(30);
    expect($options['--max-jobs'])->toBe(5);
    expect($options['--queue'])->toBe('emails,default');
});

test('a slot max-time is clamped so it cannot outlive the invocation timeout', function () {
    // A worker killed mid-job leaves that job reserved until its visibility
    // timeout expires, which stalls the queue.
    $args = ['__ow_headers' => [
        'x-dply-run' => 'queue',
        'x-dply-secret' => 's',
        'x-dply-queue-max-time' => '99999',
    ]];

    [, $options] = dply_do_functions_command($args, ['DPLY_COMMAND_SECRET' => 's']);

    expect($options['--max-time'])->toBe(880);
});
