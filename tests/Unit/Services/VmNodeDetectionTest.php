<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VmNodeDetectionTest;

use App\Modules\Deploy\Services\RepositoryRuntimeDetector;

test('a next.js package.json is detected as nextjs', function () {
    $stack = app(RepositoryRuntimeDetector::class)->detectNodeStack([
        'dependencies' => ['next' => '^15.0.0', 'react' => '^19.0.0'],
        'scripts' => ['build' => 'next build', 'start' => 'next start'],
    ], []);

    expect($stack)->not->toBeNull()
        ->and($stack['framework'])->toBe('nextjs')
        ->and($stack['language'])->toBe('node')
        ->and($stack['build_command'])->toBe('npm install && npm run build');
});

test('an express package.json is detected as express', function () {
    $stack = app(RepositoryRuntimeDetector::class)->detectNodeStack([
        'dependencies' => ['express' => '^4.19.0'],
    ], []);

    expect($stack['framework'])->toBe('express')
        ->and($stack['build_command'])->toBe('npm install');
});

test('no package.json yields no node stack', function () {
    expect(app(RepositoryRuntimeDetector::class)->detectNodeStack(null, []))->toBeNull();
});
