<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Sites\QueueWorkerClassifierTest;

use App\Support\Sites\QueueWorkerClassifier;

test('the real program from the outbidpixels site is a queue worker', function () {
    $command = "php artisan queue:work --queue='default' --sleep=3 --timeout=60 --tries=3 --memory=128 --max-time=3600";

    expect(QueueWorkerClassifier::isQueueWorker($command))->toBeTrue()
        ->and(QueueWorkerClassifier::queueNameFrom($command))->toBe('default');
});

test('horizon consumers count, horizon control commands do not', function () {
    // horizon:snapshot is a metrics cron and terminate/pause are one-shot
    // controls — none of them consume jobs, so none belong on the Queue page.
    expect(QueueWorkerClassifier::isQueueWorker('php artisan horizon'))->toBeTrue()
        ->and(QueueWorkerClassifier::isQueueWorker('php artisan horizon:work redis'))->toBeTrue()
        ->and(QueueWorkerClassifier::isQueueWorker('php artisan horizon:snapshot'))->toBeFalse()
        ->and(QueueWorkerClassifier::isQueueWorker('php artisan horizon:terminate'))->toBeFalse();
});

test('ordinary daemons stay on the Workers page', function () {
    // Hiding someone's daemon from the page they manage it on is the worse
    // failure, so anything not recognisably a consumer stays put.
    expect(QueueWorkerClassifier::isQueueWorker('node /home/dply/app/worker.js'))->toBeFalse()
        ->and(QueueWorkerClassifier::isQueueWorker('php artisan reverb:start'))->toBeFalse()
        ->and(QueueWorkerClassifier::isQueueWorker('php artisan schedule:work'))->toBeFalse()
        ->and(QueueWorkerClassifier::isQueueWorker(null))->toBeFalse()
        ->and(QueueWorkerClassifier::isQueueWorker('  '))->toBeFalse();
});

test('the queue name is read from either flag form, and multi-queue is kept whole', function () {
    expect(QueueWorkerClassifier::queueNameFrom('php artisan queue:work --queue=emails'))->toBe('emails')
        ->and(QueueWorkerClassifier::queueNameFrom('php artisan queue:work --queue "high"'))->toBe('high')
        // One process draining several in priority order — splitting it here
        // would invent two workers that do not exist.
        ->and(QueueWorkerClassifier::queueNameFrom('php artisan queue:work --queue=high,default'))->toBe('high,default')
        ->and(QueueWorkerClassifier::queueNameFrom('php artisan queue:work'))->toBeNull();
});
