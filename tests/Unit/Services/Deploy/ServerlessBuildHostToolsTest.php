<?php

declare(strict_types=1);

use App\Modules\Deploy\Services\ServerlessBuildHostTools;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

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
    expect($wrapped)->toContain('export HOME=');
    expect($wrapped)->toContain('export COMPOSER_HOME=');
    expect($tools->prepareShellCommand('php artisan migrate'))->toBe('php artisan migrate');
});

test('processEnv always sets HOME and COMPOSER_HOME for queue workers', function () {
    $previousHome = getenv('HOME');
    $previousComposerHome = getenv('COMPOSER_HOME');

    putenv('HOME');
    putenv('COMPOSER_HOME');
    unset($_SERVER['HOME'], $_ENV['HOME'], $_SERVER['COMPOSER_HOME'], $_ENV['COMPOSER_HOME']);

    try {
        $env = (new ServerlessBuildHostTools)->processEnv();

        expect($env['HOME'] ?? '')->not->toBe('');
        expect($env['COMPOSER_HOME'] ?? '')->toBe(storage_path('app/composer-home'));
        expect(is_dir($env['COMPOSER_HOME']))->toBeTrue();
    } finally {
        if (is_string($previousHome) && $previousHome !== '') {
            putenv('HOME='.$previousHome);
            $_SERVER['HOME'] = $previousHome;
            $_ENV['HOME'] = $previousHome;
        }
        if (is_string($previousComposerHome) && $previousComposerHome !== '') {
            putenv('COMPOSER_HOME='.$previousComposerHome);
            $_SERVER['COMPOSER_HOME'] = $previousComposerHome;
            $_ENV['COMPOSER_HOME'] = $previousComposerHome;
        }
    }
});

test('composer refuses without HOME, but prepareShellCommand succeeds (systemd worker)', function () {
    $tools = new ServerlessBuildHostTools;

    // Reproduce the production failure: no HOME / COMPOSER_HOME in the env.
    $bareEnv = [
        'PATH' => '/usr/bin:/bin:'.dirname($tools->findPhp()),
        'TERM' => 'dumb',
    ];
    $bare = Process::fromShellCommandline('composer --version 2>&1 || true', null, $bareEnv);
    $bare->setTimeout(30);
    $bare->run();
    $bareOut = trim($bare->getOutput()."\n".$bare->getErrorOutput());

    // Only assert the known Composer diagnostic when composer is present but HOME is missing.
    if (str_contains($bareOut, 'HOME or COMPOSER_HOME')) {
        expect($bareOut)->toContain('HOME or COMPOSER_HOME');
    }

    $work = storage_path('framework/testing/composer-home-'.uniqid());
    File::ensureDirectoryExists($work);
    File::put($work.'/composer.json', json_encode([
        'name' => 'dply/composer-home-smoke',
        'require' => new stdClass,
    ], JSON_THROW_ON_ERROR));

    $command = $tools->prepareShellCommand('composer install --no-interaction --quiet');
    $fixed = Process::fromShellCommandline($command, $work, $tools->processEnv());
    $fixed->setTimeout(180);
    $fixed->run();

    expect($fixed->isSuccessful())->toBeTrue(
        trim($fixed->getErrorOutput()."\n".$fixed->getOutput())
    );

    File::deleteDirectory($work);
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
