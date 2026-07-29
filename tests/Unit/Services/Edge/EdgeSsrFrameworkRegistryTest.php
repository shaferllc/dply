<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Edge;

use App\Modules\Edge\Services\Frameworks\EdgeFrameworkPresetRegistry;
use App\Modules\Edge\Services\Ssr\EdgeSsrFrameworkRegistry;

test('detects keel from @shaferllc/keel before hono', function () {
    $profile = EdgeSsrFrameworkRegistry::detectInProject([
        'dependencies' => [
            '@shaferllc/keel' => '^0.86.0',
            'hono' => '^4.6.14',
        ],
    ]);

    expect($profile)->not->toBeNull()
        ->and($profile->slug)->toBe('keel')
        ->and($profile->workerPath)->toBe('.dply-keel-bundle/worker.js')
        ->and($profile->assetsPath)->toBe('public')
        ->and($profile->buildCommandOverride)->toContain('wrangler deploy --dry-run');
});

test('keel framework preset defaults to hybrid not worker ssr', function () {
    $preset = EdgeFrameworkPresetRegistry::find('keel');

    expect($preset)->not->toBeNull()
        ->and($preset->runtimeMode)->toBe('hybrid')
        ->and($preset->outputDir)->toBe('public')
        ->and($preset->packageDependencies)->toContain('@shaferllc/keel');
});

test('detection plan with keel maps to keel preset not hono', function () {
    $preset = EdgeFrameworkPresetRegistry::byDetectionPlan([
        'framework' => 'keel',
        'runtime' => 'node',
        'dependencies' => ['@shaferllc/keel', 'hono'],
    ]);

    expect($preset->slug)->toBe('keel');
});
