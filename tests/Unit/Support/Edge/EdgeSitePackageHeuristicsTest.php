<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Edge;

use App\Modules\Edge\Support\EdgeSitePackageHeuristics;

test('flags withastro-style framework monorepo roots', function () {
    $pkg = [
        'name' => 'root',
        'private' => true,
        'workspaces' => [
            'packages/*',
            'packages/integrations/*',
        ],
        'scripts' => [
            'build' => 'turbo run build --filter=astro --filter=create-astro',
        ],
        'dependencies' => [
            'astro-benchmark' => 'workspace:*',
        ],
        'devDependencies' => [
            'turbo' => '^2.0.0',
        ],
    ];

    expect(EdgeSitePackageHeuristics::looksLikeNonDeployablePackageRoot($pkg))->toBeTrue();
});

test('allows real Astro site packages', function () {
    $pkg = [
        'name' => '@example/basics',
        'private' => true,
        'scripts' => [
            'build' => 'astro build',
        ],
        'dependencies' => [
            'astro' => '^5.0.0',
        ],
    ];

    expect(EdgeSitePackageHeuristics::looksLikeNonDeployablePackageRoot($pkg))->toBeFalse();
});

test('allows app packages inside a monorepo that declare a framework', function () {
    $pkg = [
        'name' => 'web',
        'private' => true,
        'scripts' => [
            'build' => 'next build',
        ],
        'dependencies' => [
            'next' => '15.0.0',
            'react' => '19.0.0',
        ],
    ];

    expect(EdgeSitePackageHeuristics::looksLikeNonDeployablePackageRoot($pkg))->toBeFalse();
});

test('flags next.js-style tooling roots without a site framework dep', function () {
    $pkg = [
        'name' => 'nextjs-project',
        'private' => true,
        'workspaces' => ['packages/*'],
        'scripts' => [
            'build' => 'turbo run build --remote-cache-timeout 60',
        ],
        'devDependencies' => [
            'turbo' => '2.0.0',
        ],
    ];

    expect(EdgeSitePackageHeuristics::looksLikeNonDeployablePackageRoot($pkg))->toBeTrue();
});
