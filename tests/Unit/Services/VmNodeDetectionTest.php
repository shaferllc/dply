<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VmNodeDetectionTest;

use App\Modules\Deploy\Contracts\RepositoryFiles;
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

/**
 * An in-memory checkout: path => contents. Absent keys do not exist.
 *
 * @param  array<string, string>  $files
 */
function fakeRepo(array $files): RepositoryFiles
{
    return new class($files) implements RepositoryFiles
    {
        /** @param array<string, string> $files */
        public function __construct(private readonly array $files) {}

        public function exists(string $path): bool
        {
            return array_key_exists(ltrim($path, '/'), $this->files);
        }

        public function read(string $path): ?string
        {
            return $this->files[ltrim($path, '/')] ?? null;
        }
    };
}

test('the framework pass still declines a package.json with no framework and no build script', function () {
    // Precedence guard: detectNodeStack() runs before Go/Symfony in detect(),
    // so it must keep returning null here or a Go repo carrying a frontend
    // package.json would be classified as node.
    $stack = app(RepositoryRuntimeDetector::class)->detectNodeStack([
        'dependencies' => ['hono' => '^4.6.14'],
        'scripts' => ['dev' => 'wrangler dev', 'deploy' => 'wrangler deploy'],
    ], []);

    expect($stack)->toBeNull();
});

test('a package.json with no framework and no build script is detected as generic node', function () {
    $files = fakeRepo(['package.json' => json_encode([
        'dependencies' => ['hono' => '^4.6.14'],
        'scripts' => ['dev' => 'wrangler dev', 'deploy' => 'wrangler deploy'],
    ])]);

    $result = app(RepositoryRuntimeDetector::class)->detect($files, ['supports_node_runtime' => true]);

    expect($result['language'])->toBe('node')
        ->and($result['framework'])->toBe('node_generic');
});

test('a go module carrying a frontend package.json is still detected as go', function () {
    $files = fakeRepo([
        'go.mod' => "module example.com/app\n\ngo 1.23\n",
        'main.go' => "package main\n\nfunc main() {}\n",
        'package.json' => json_encode(['scripts' => ['dev' => 'vite']]),
    ]);

    $result = app(RepositoryRuntimeDetector::class)->detect($files, ['supports_go_runtime' => true]);

    expect($result['language'])->toBe('go');
});
