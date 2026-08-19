<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use App\Services\WorkerPools\WorkerPhpVersion;

test('prefers the origin site php over the app server meta', function () {
    $source = new Server(['meta' => [
        'php_version' => '8.3',
        'default_php_version' => '8.3',
    ]]);
    $site = new Site([
        'runtime' => 'php',
        'runtime_version' => '8.4',
        'type' => SiteType::Php,
    ]);

    $meta = app(WorkerPhpVersion::class)->applyToMeta(['php_version' => '8.3'], $source, $site);

    expect($meta['php_version'])->toBe('8.4')
        ->and($meta['default_php_version'])->toBe('8.4');
});

test('falls back to the source server pin when the site has no php version', function () {
    $source = new Server(['meta' => ['default_php_version' => '8.2']]);
    $site = new Site([
        'runtime' => 'php',
        'runtime_version' => null,
        'type' => SiteType::Php,
    ]);

    expect(app(WorkerPhpVersion::class)->forWorker($source, $site))->toBe('8.2');
});

test('normalizes dotted php versions and rejects junk', function () {
    $php = app(WorkerPhpVersion::class);

    expect($php->normalize('PHP 8.4.1'))->toBe('8.4')
        ->and($php->normalize(8.3))->toBe('8.3')
        ->and($php->normalize('none'))->toBeNull()
        ->and($php->normalize(null))->toBeNull();
});
