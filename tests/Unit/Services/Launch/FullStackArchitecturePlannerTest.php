<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Launch;

use App\Modules\Launch\Services\FullStackArchitecturePlanner;
use Illuminate\Support\Facades\File;

test('full stack planner recommends edge and cloud for ssr node repo', function (): void {
    $root = sys_get_temp_dir().'/dply-fs-next-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($root);
    File::put($root.'/package.json', json_encode([
        'name' => 'storefront',
        'scripts' => [
            'build' => 'next build',
            'start' => 'next start',
        ],
        'dependencies' => [
            'next' => '^14.0.0',
        ],
    ], JSON_THROW_ON_ERROR));

    try {
        $plan = app(FullStackArchitecturePlanner::class)->planFromCheckout(
            'https://github.com/acme/storefront.git',
            'main',
            $root,
        );

        expect($plan->hasLayer('edge_front'))->toBeTrue()
            ->and($plan->hasLayer('cloud_origin'))->toBeTrue()
            ->and($plan->hasLayer('byo_database'))->toBeTrue()
            ->and($plan->wiringHints)->not->toBeEmpty();
    } finally {
        File::deleteDirectory($root);
    }
});

test('full stack planner recommends byo api for php repo', function (): void {
    $root = sys_get_temp_dir().'/dply-fs-laravel-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($root);
    File::put($root.'/composer.json', json_encode([
        'name' => 'acme/api',
        'require' => [
            'php' => '^8.3',
            'laravel/framework' => '^11.0',
        ],
    ], JSON_THROW_ON_ERROR));

    try {
        $plan = app(FullStackArchitecturePlanner::class)->planFromCheckout(
            'https://github.com/acme/api.git',
            'main',
            $root,
        );

        expect($plan->hasLayer('byo_api'))->toBeTrue()
            ->and($plan->hasLayer('edge_front'))->toBeFalse();
    } finally {
        File::deleteDirectory($root);
    }
});

test('full stack planner splits monorepo packages', function (): void {
    $root = sys_get_temp_dir().'/dply-fs-mono-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($root.'/apps/web');
    File::ensureDirectoryExists($root.'/apps/api');
    File::put($root.'/turbo.json', '{}');
    File::put($root.'/apps/web/package.json', json_encode([
        'name' => '@acme/web',
        'scripts' => ['build' => 'vite build', 'start' => 'vite preview'],
        'dependencies' => ['vite' => '^5.0.0'],
    ], JSON_THROW_ON_ERROR));
    File::put($root.'/apps/api/composer.json', json_encode([
        'require' => ['php' => '^8.3', 'laravel/framework' => '^11.0'],
    ], JSON_THROW_ON_ERROR));

    try {
        $plan = app(FullStackArchitecturePlanner::class)->planFromCheckout(
            'https://github.com/acme/monorepo.git',
            'main',
            $root,
        );

        expect($plan->isMonorepo)->toBeTrue()
            ->and($plan->hasLayer('edge_front'))->toBeTrue()
            ->and($plan->hasLayer('byo_api'))->toBeTrue();
    } finally {
        File::deleteDirectory($root);
    }
});
