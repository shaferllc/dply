<?php

declare(strict_types=1);

namespace Tests\Unit\Queue\QueueEndpointTest;

use App\Modules\Queue\Support\QueueEndpoint;

/**
 * One resolver, three levels, and a reachability rule.
 *
 * The rule is the point: an endpoint that looks configured but is unroutable is
 * worse than none, because it gets written into a customer's .env and fails on
 * every push. So a local-looking host resolves to '' and the whole managed-queue
 * surface hides itself rather than handing out an address nothing can reach.
 */
test('the endpoint resolves through the documented preference order', function (
    string $queueUrl,
    string $publicAppUrl,
    string $appUrl,
    string $expected,
) {
    config([
        'queue_service.public_url' => $queueUrl,
        'dply.public_app_url' => $publicAppUrl,
        'app.url' => $appUrl,
    ]);

    expect(QueueEndpoint::base())->toBe($expected);
})->with([
    'explicit wins over everything' => [
        'https://tunnel.example/api/queue/v1', 'dply.io', 'https://dply.io', 'https://tunnel.example/api/queue/v1',
    ],
    'platform public url is second' => [
        '', 'dply.io', 'https://elsewhere.test', 'https://dply.io/api/queue/v1',
    ],
    'a bare host gains a scheme' => [
        '', 'dply.io', '', 'https://dply.io/api/queue/v1',
    ],
    // The case that made managed queues invisible in production: requiring a
    // second variable that merely repeats APP_URL.
    'APP_URL is the third level' => [
        '', '', 'https://dply.io', 'https://dply.io/api/queue/v1',
    ],
    'a custom domain works too' => [
        '', '', 'https://app.mycompany.com', 'https://app.mycompany.com/api/queue/v1',
    ],
]);

test('a host a customer server could not reach is refused', function (string $appUrl) {
    config(['queue_service.public_url' => '', 'dply.public_app_url' => '', 'app.url' => $appUrl]);

    expect(QueueEndpoint::base())->toBe('');
})->with([
    'https://dply.test',
    'http://localhost:8000',
    'https://site.local',
    'http://127.0.0.1',
    'http://192.168.1.10',
    'http://10.0.0.5',
    '',
]);
