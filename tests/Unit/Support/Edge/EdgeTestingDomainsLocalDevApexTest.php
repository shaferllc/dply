<?php

declare(strict_types=1);

use App\Modules\Edge\Support\EdgeTestingDomains;

test('localDevApex prefers non on-dply testing domain', function () {
    config([
        'edge.testing_domains' => ['on-dply.site', 'edge.test'],
        'app.url' => 'https://dply.test',
    ]);

    expect(EdgeTestingDomains::localDevApex())->toBe('edge.test');
});

test('localDevApex falls back to app url host when only on-dply is configured', function () {
    config([
        'edge.testing_domains' => ['on-dply.site'],
        'app.url' => 'https://dply.test',
    ]);

    expect(EdgeTestingDomains::localDevApex())->toBe('dply.test');
});

test('localDevApex keeps on-dply when no local option exists', function () {
    config([
        'edge.testing_domains' => ['on-dply.site'],
        'app.url' => 'https://app.example.com',
    ]);

    expect(EdgeTestingDomains::localDevApex())->toBe('on-dply.site');
});
