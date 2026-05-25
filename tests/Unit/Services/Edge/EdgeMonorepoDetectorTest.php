<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Edge;

use App\Services\Edge\EdgeMonorepoDetector;
use Illuminate\Support\Facades\File;

test('detects monorepo markers and package directories', function () {
    $root = sys_get_temp_dir().'/dply-monorepo-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($root.'/apps/web');
    File::put($root.'/turbo.json', '{}');
    File::put($root.'/apps/web/package.json', json_encode(['name' => '@acme/web'], JSON_THROW_ON_ERROR));

    try {
        $result = app(EdgeMonorepoDetector::class)->inspectDirectory($root);

        expect($result['is_monorepo'])->toBeTrue();
        expect($result['markers'])->toContain('turbo.json');
        expect(collect($result['packages'])->pluck('path')->all())->toContain('apps/web');
    } finally {
        File::deleteDirectory($root);
    }
});

test('single package repo is not treated as monorepo', function () {
    $root = sys_get_temp_dir().'/dply-single-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($root);
    File::put($root.'/package.json', json_encode(['name' => 'solo-app'], JSON_THROW_ON_ERROR));

    try {
        $result = app(EdgeMonorepoDetector::class)->inspectDirectory($root);

        expect($result['is_monorepo'])->toBeFalse();
        expect($result['packages'])->toHaveCount(1);
    } finally {
        File::deleteDirectory($root);
    }
});
