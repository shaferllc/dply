<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Edge;

use App\Modules\Edge\Support\EdgeSsrDetection;

test('detects ssr frameworks with start command', function () {
    expect(EdgeSsrDetection::planLooksLikeSsr([
        'framework' => 'next',
        'start_command' => 'next start',
        'build_command' => 'npm run build',
    ]))->toBeTrue();
});

test('static export scripts are not treated as ssr', function () {
    expect(EdgeSsrDetection::planLooksLikeSsr([
        'framework' => 'next',
        'start_command' => 'next start',
        'build_command' => 'npm run build && next export',
    ]))->toBeFalse();
});

test('non ssr frameworks are ignored', function () {
    expect(EdgeSsrDetection::planLooksLikeSsr([
        'framework' => 'vite',
        'start_command' => 'vite preview',
    ]))->toBeFalse();
});
