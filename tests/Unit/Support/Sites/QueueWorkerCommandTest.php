<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Sites\QueueWorkerCommandTest;

use App\Support\Sites\QueueWorkerCommand;

test('it reads the flags dply owns', function () {
    $c = QueueWorkerCommand::parse("php artisan queue:work redis --queue='high,default' --tries=5 --timeout=90 --stop-when-empty");

    expect($c->connection)->toBe('redis');
    expect($c->queues())->toBe(['high', 'default']);
    expect($c->flags['tries'])->toBe('5');
    expect($c->bools)->toContain('stop-when-empty');
});

test('space-separated flag values are read too', function () {
    // Laravel accepts `--queue default`, and treating the value as a positional
    // argument would read it as the connection name.
    $c = QueueWorkerCommand::parse('php artisan queue:work --queue default --tries 3');

    expect($c->queues())->toBe(['default']);
    expect($c->connection)->toBeNull();
    expect($c->flags['tries'])->toBe('3');
});

test('flags dply does not model survive an edit', function () {
    // THE reason this class exists: editing --tries must not cost someone their
    // hand-written flags or their pinned php binary.
    $original = 'nice -n 10 /usr/bin/php8.3 -d memory_limit=512M artisan queue:work redis --queue=default --tries=3 --force --env=staging';

    $edited = QueueWorkerCommand::parse($original)->with(['tries' => 9])->render();

    expect($edited)->toContain('--tries=9');
    expect($edited)->toContain('--force');
    expect($edited)->toContain('--env=staging');
    expect($edited)->toStartWith('nice -n 10 /usr/bin/php8.3 -d memory_limit=512M artisan queue:work');
});

test('an empty value removes a flag rather than writing a zero', function () {
    // --backoff=0 is not "no backoff" to every driver.
    $c = QueueWorkerCommand::parse('php artisan queue:work --queue=default --backoff=30');

    expect($c->with(['backoff' => ''])->render())->not->toContain('--backoff');
});

test('pausing drops one queue and keeps the rest', function () {
    $c = QueueWorkerCommand::parse('php artisan queue:work --queue=high,default,low');

    expect($c->withQueues(['high', 'low'])->render())->toContain('--queue=high,low');
});

test('a command that is not a worker is returned untouched', function () {
    // Nothing here is dply's to rewrite, so render() must be a round trip.
    $command = 'node /srv/app/listener.js --verbose';

    expect(QueueWorkerCommand::parse($command)->render())->toBe($command);
});

test('rendering is stable, so an unchanged worker produces an identical line', function () {
    $c = QueueWorkerCommand::parse('php artisan queue:work --tries=3 --queue=default --timeout=60');

    expect(QueueWorkerCommand::parse($c->render())->render())->toBe($c->render());
});

test('a queue name needing quotes keeps them', function () {
    $rendered = QueueWorkerCommand::parse('php artisan queue:work')->with(['queue' => 'needs space'])->render();

    expect($rendered)->toContain("--queue='needs space'");
    expect(QueueWorkerCommand::parse($rendered)->queues())->toBe(['needs space']);
});
