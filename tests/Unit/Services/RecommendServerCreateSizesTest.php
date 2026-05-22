<?php

declare(strict_types=1);

namespace Tests\Unit\Services\RecommendServerCreateSizesTest;
use App\Actions\Servers\RecommendServerCreateSizes;
test('marks tiny database node as too small', function () {
    $result = RecommendServerCreateSizes::run('database', [
        ['value' => 'tiny', 'memory_mb' => 1024, 'vcpus' => 1, 'disk_gb' => 25],
        ['value' => 'balanced', 'memory_mb' => 4096, 'vcpus' => 2, 'disk_gb' => 80],
    ]);

    expect($result['tiny']['state'])->toBe('too_small');
    expect($result['balanced']['state'])->toBe('good_starting_point');
});
test('marks large plain server as overkill', function () {
    $result = RecommendServerCreateSizes::run('plain', [
        ['value' => 'large', 'memory_mb' => 16384, 'vcpus' => 8, 'disk_gb' => 320],
    ]);

    expect($result['large']['state'])->toBe('overkill');
});
