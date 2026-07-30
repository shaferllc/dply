<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Edge;

use App\Modules\Edge\Support\EdgeBuildDockerBootstrap;

test('probe detail mentions missing cli or daemon error', function () {
    $detail = EdgeBuildDockerBootstrap::probeDetail();

    expect($detail)->toBeString()->not->toBeEmpty();
});

test('local desktop environment detects testing/local app env', function () {
    config(['app.env' => 'local']);

    expect(EdgeBuildDockerBootstrap::isLocalDesktopEnvironment())->toBeTrue();
});

test('queue user defaults to www-data for control-plane Horizon', function () {
    config(['edge.build.docker_user' => 'www-data']);

    expect(EdgeBuildDockerBootstrap::queueUser())->toBe('www-data');
});

test('queue user rejects invalid names', function () {
    config(['edge.build.docker_user' => 'www-data;rm']);

    expect(EdgeBuildDockerBootstrap::queueUser())->toBe('www-data');
});
