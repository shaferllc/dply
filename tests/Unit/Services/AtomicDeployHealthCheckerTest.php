<?php

namespace Tests\Unit\Services\AtomicDeployHealthCheckerTest;

use App\Services\Sites\AtomicDeployHealthChecker;

test('normalize path', function () {
    $c = new AtomicDeployHealthChecker;

    expect($c->normalizePath(''))->toBe('/');
    expect($c->normalizePath('/health'))->toBe('/health');
    expect($c->normalizePath('health'))->toBe('/health');
    expect($c->normalizePath('/v1/up'))->toBe('/v1/up');
});
