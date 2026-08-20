<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ServerlessLaravelOptimizeCacheTest;

use App\Modules\Deploy\Services\ServerlessLaravelOptimizeCache;

test('it removes a config cache that baked the build machine storage path', function () {
    $dir = sys_get_temp_dir().'/dply-opt-cache-'.uniqid();
    mkdir($dir.'/bootstrap/cache', 0777, true);

    $buildStorage = '/home/dply/dplyio/storage/app/serverless-repositories/build-xyz/repo/storage';
    file_put_contents($dir.'/bootstrap/cache/config.php', '<?php return '.var_export([
        'logging' => [
            'channels' => [
                'single' => ['path' => $buildStorage.'/logs/laravel.log'],
            ],
        ],
        'view' => ['compiled' => $buildStorage.'/framework/views'],
    ], true).';');
    file_put_contents($dir.'/bootstrap/cache/routes.php', '<?php return [];');
    file_put_contents($dir.'/bootstrap/cache/events.php', '<?php return [];');

    $note = (new ServerlessLaravelOptimizeCache)->neutralize($dir);

    expect($dir.'/bootstrap/cache/config.php')->not->toBeFile();
    expect($dir.'/bootstrap/cache/routes.php')->toBeFile();
    expect($dir.'/bootstrap/cache/events.php')->toBeFile();
    expect($note)->toContain('bootstrap/cache/config.php');
    expect((string) file_get_contents($dir.'/bootstrap/cache/routes.php'))->not->toContain($buildStorage);
});

test('it is a no op when config was not cached', function () {
    $dir = sys_get_temp_dir().'/dply-opt-cache-empty-'.uniqid();
    mkdir($dir.'/bootstrap/cache', 0777, true);

    expect((new ServerlessLaravelOptimizeCache)->neutralize($dir))->toBe('');
});
