<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Sites\MissingPhpExtensionResolverTest;

use App\Modules\Remediations\Services\Actions\InstallPhpExtensionAction;
use App\Modules\Remediations\Services\RemediationCatalog;
use App\Support\Sites\MissingPhpExtensionResolver;
use ReflectionClass;

test('the imagick class error resolves to the imagick extension', function () {
    // The real line, from a site's laravel.log.
    $log = 'local.ERROR: Class "Imagick" not found {"exception":"[object] (Error(code: 0): '
        .'Class \\"Imagick\\" not found at /home/dply/outbidpixels/app/Jobs/CompositeGrid.php:33)';

    expect(MissingPhpExtensionResolver::fromErrorText($log))->toBe('imagick');
});

test('an undefined extension function resolves to its extension', function () {
    expect(MissingPhpExtensionResolver::fromErrorText('Call to undefined function imagecreatetruecolor()'))->toBe('gd')
        ->and(MissingPhpExtensionResolver::fromErrorText('Call to undefined function bcadd()'))->toBe('bcmath');
});

test('an application class that is merely missing resolves to nothing', function () {
    // The dangerous false positive: this is a broken autoload or a typo, and
    // installing a PHP extension for it would be nonsense.
    expect(MissingPhpExtensionResolver::fromErrorText('Class "App\\Models\\Thing" not found'))->toBeNull()
        ->and(MissingPhpExtensionResolver::fromErrorText(''))->toBeNull()
        ->and(MissingPhpExtensionResolver::fromErrorText(null))->toBeNull();
});

test('redis keeps its own remediation, which knows about the PECL fallback', function () {
    expect(MissingPhpExtensionResolver::fromErrorText('Class "Redis" not found'))->toBeNull();

    $catalog = app(RemediationCatalog::class);

    expect($catalog->match('Class "Redis" not found')['code'] ?? null)->toBe('php_ext_redis_missing')
        ->and($catalog->match('could not find driver')['code'] ?? null)->toBe('php_pdo_driver_missing');
});

test('the imagick error matches the generic extension remediation and its handler is allow-listed', function () {
    $catalog = app(RemediationCatalog::class);
    $match = $catalog->match('Class "Imagick" not found');

    expect($match['code'] ?? null)->toBe('php_ext_missing')
        ->and($catalog->handlerClasses())->toContain(InstallPhpExtensionAction::class);
});

test('every mapped extension is one the server manager can actually install', function () {
    // A card that offers an install the catalog cannot perform is worse than no
    // card: it fails after the operator commits to it. iconv/pcntl/posix were
    // mapped and are not in the catalog — this is the guard that caught them.
    $catalogIds = collect(config('server_php_extensions.extensions'))->pluck('id')->filter()->all();

    $reflection = new ReflectionClass(MissingPhpExtensionResolver::class);
    $mapped = array_unique(array_merge(
        array_values($reflection->getConstant('CLASSES')),
        array_values($reflection->getConstant('FUNCTIONS')),
    ));

    expect(array_values(array_diff($mapped, $catalogIds)))->toBe([]);
});
