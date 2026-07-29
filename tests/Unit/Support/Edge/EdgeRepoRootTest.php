<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Edge;

use App\Modules\Edge\Support\EdgeRepoRoot;

test('normalize trims slashes and rejects traversal', function () {
    expect(EdgeRepoRoot::normalize(' apps/web/ '))->toBe('apps/web');
    expect(EdgeRepoRoot::normalize('../secret'))->toBe('');
    expect(EdgeRepoRoot::normalize('apps/../web'))->toBe('');
});

test('push touches site when repo root is empty', function () {
    expect(EdgeRepoRoot::pushTouchesSite('', ['README.md']))->toBeTrue();
});

test('push ignores files outside configured repo root', function () {
    expect(EdgeRepoRoot::pushTouchesSite('apps/web', [
        'apps/api/src/index.ts',
        'packages/shared/foo.ts',
    ]))->toBeFalse();

    expect(EdgeRepoRoot::pushTouchesSite('apps/web', [
        'apps/web/package.json',
        'apps/web/src/app/page.tsx',
    ]))->toBeTrue();
});

test('push redeploys when repo level dply config changes', function () {
    expect(EdgeRepoRoot::pushTouchesSite('apps/web', ['dply.toml']))->toBeTrue();
    expect(EdgeRepoRoot::pushTouchesSite('apps/web', ['apps/web/dply.yaml']))->toBeTrue();
});

test('changed files are collected from github push payload', function () {
    $files = EdgeRepoRoot::changedFilesFromPushPayload([
        'commits' => [[
            'added' => ['apps/web/foo.js'],
            'modified' => ['dply.toml'],
            'removed' => [],
        ]],
        'head_commit' => [
            'modified' => ['apps/web/bar.js'],
        ],
    ]);

    expect($files)->toEqual([
        'apps/web/foo.js',
        'dply.toml',
        'apps/web/bar.js',
    ]);
});
