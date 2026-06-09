<?php

declare(strict_types=1);

namespace Tests\Unit\LaravelComposerPackageDetectorTest;

use App\Services\Deploy\LaravelComposerPackageDetector;

test('flags are false when composer is null', function () {
    $d = new LaravelComposerPackageDetector;
    $flags = $d->flags(null);

    expect($flags['octane'])->toBeFalse();
    expect($flags['horizon'])->toBeFalse();
    expect($flags['pulse'])->toBeFalse();
    expect($flags['reverb'])->toBeFalse();
});
test('detects packages in require or require dev', function () {
    $d = new LaravelComposerPackageDetector;
    $flags = $d->flags([
        'require' => [
            'laravel/octane' => '^2.0',
            'laravel/horizon' => '^5.0',
        ],
        'require-dev' => [
            'laravel/pulse' => '^1.0',
            'laravel/reverb' => '^1.0',
        ],
    ]);

    expect($flags['octane'])->toBeTrue();
    expect($flags['horizon'])->toBeTrue();
    expect($flags['pulse'])->toBeTrue();
    expect($flags['reverb'])->toBeTrue();
});
