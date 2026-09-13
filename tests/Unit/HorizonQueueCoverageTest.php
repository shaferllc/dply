<?php

/*
 * A queue no production supervisor consumes strands every job sent to it.
 * dply-background sat unconsumed from 2026-08-10 until this test existed.
 */
it('has a production Horizon supervisor for every queue the app dispatches to', function (string $key): void {
    $queue = config($key);

    $consumed = collect(config('horizon.environments.production'))
        ->flatMap(fn (array $supervisor): array => (array) $supervisor['queue'])
        ->all();

    expect($queue)->toBeString()->not->toBeEmpty()
        ->and($consumed)->toContain($queue);
})->with([
    'dply.queues.interactive',
    'dply.queues.background',
    'server_services.sync_queue',
    'server_manage.remote_task_queue',
]);
