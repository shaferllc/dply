<?php

declare(strict_types=1);

use App\Modules\Deploy\Services\ServerlessBuildHostTools;
use Illuminate\Support\Facades\File;

test('commandNeedsComposer detects composer install and chained forms', function () {
    $tools = new ServerlessBuildHostTools;

    expect($tools->commandNeedsComposer('composer install --no-dev'))->toBeTrue();
    expect($tools->commandNeedsComposer('cd app && composer install'))->toBeTrue();
    expect($tools->commandNeedsComposer('composer'))->toBeTrue();
    expect($tools->commandNeedsComposer('php artisan migrate'))->toBeFalse();
    expect($tools->commandNeedsComposer('echo composerish'))->toBeFalse();
});

test('prepareShellCommand wraps composer installs with an on-demand installer', function () {
    $tools = new ServerlessBuildHostTools;
    $wrapped = $tools->prepareShellCommand('composer install --no-dev --optimize-autoloader');

    expect($wrapped)->toContain('getcomposer.org/installer');
    expect($wrapped)->toContain('composer install --no-dev --optimize-autoloader');
    expect($wrapped)->toContain(storage_path('app/bin'));
    expect($tools->prepareShellCommand('php artisan migrate'))->toBe('php artisan migrate');
});

test('withComposerBinary rewrites the first composer token to an absolute path', function () {
    $tools = new ServerlessBuildHostTools;

    expect($tools->withComposerBinary(
        'composer install --no-dev --optimize-autoloader',
        '/var/www/dply/storage/app/bin/composer',
    ))->toBe("'/var/www/dply/storage/app/bin/composer' install --no-dev --optimize-autoloader");

    expect($tools->withComposerBinary(
        'cd app && composer install',
        '/usr/local/bin/composer',
    ))->toBe("cd app && '/usr/local/bin/composer' install");
});

test('enrichedPath prefers storage bin and common install locations', function () {
    $path = (new ServerlessBuildHostTools)->enrichedPath();

    expect($path)->toContain(storage_path('app/bin'));
    expect($path)->toContain('/usr/local/bin');
});

test('ensureComposer returns an existing binary without reinstalling', function () {
    $binDir = storage_path('app/bin');
    File::ensureDirectoryExists($binDir);
    $fake = $binDir.'/composer';
    File::put($fake, "#!/bin/sh\necho ok\n");
    chmod($fake, 0755);

    try {
        $result = (new ServerlessBuildHostTools)->ensureComposer();
        expect($result['path'])->toBe($fake);
        expect($result['installed'])->toBeFalse();
    } finally {
        File::delete($fake);
    }
});
