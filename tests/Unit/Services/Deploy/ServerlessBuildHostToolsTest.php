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
    expect($wrapped)->not->toContain('Node/npm not found on the Serverless build host');
    expect($tools->prepareShellCommand('php artisan migrate'))->toBe('php artisan migrate');
});

test('commandNeedsNode detects npm pnpm yarn bun and chained forms', function () {
    $tools = new ServerlessBuildHostTools;

    expect($tools->commandNeedsNode('npm ci && npm run build'))->toBeTrue();
    expect($tools->commandNeedsNode('cd app && npm install'))->toBeTrue();
    expect($tools->commandNeedsNode('npx vite build'))->toBeTrue();
    expect($tools->commandNeedsNode('pnpm install && pnpm run build'))->toBeTrue();
    expect($tools->commandNeedsNode('yarn install && yarn build'))->toBeTrue();
    expect($tools->commandNeedsNode('bun install && bun run build'))->toBeTrue();
    expect($tools->commandNeedsNode('composer install --no-dev'))->toBeFalse();
    expect($tools->commandNeedsNode('php artisan migrate'))->toBeFalse();
    expect($tools->commandNeedsNode('echo npmish'))->toBeFalse();
    expect($tools->commandNeedsNode('bundle install'))->toBeFalse();
});

test('prepareShellCommand wraps npm commands with a Node/npm ensure prefix', function () {
    $tools = new ServerlessBuildHostTools;
    $wrapped = $tools->prepareShellCommand('npm ci && npm run build');

    expect($wrapped)->toContain('command -v npm');
    expect($wrapped)->toContain('npm ci && npm run build');
    expect($wrapped)->toContain('[dply] Node/npm not found on the Serverless build host');
    expect($wrapped)->toContain(storage_path('app/bin'));
    expect($wrapped)->toContain('mise');
    expect($wrapped)->toContain('nodejs.org/dist');
    expect($wrapped)->not->toContain('getcomposer.org/installer');
    expect($tools->prepareShellCommand('php artisan migrate'))->toBe('php artisan migrate');
});

test('prepareShellCommand wraps composer plus npm with both ensure prefixes', function () {
    $wrapped = (new ServerlessBuildHostTools)->prepareShellCommand(
        'composer install --no-dev --optimize-autoloader && npm ci && npm run build'
    );

    expect($wrapped)->toContain('getcomposer.org/installer');
    expect($wrapped)->toContain('command -v npm');
    expect($wrapped)->toContain('[dply] Node/npm not found on the Serverless build host');
    expect($wrapped)->toContain('composer install --no-dev --optimize-autoloader && npm ci && npm run build');
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
    expect($path)->toContain(storage_path('app/node/bin'));
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

test('build env suppresses colour so deploy logs stay plain text', function () {
    $env = (new ServerlessBuildHostTools)->processEnv();

    expect($env['NO_COLOR'])->toBe('1');
    expect($env['TERM'])->toBe('dumb');
    expect($env['COMPOSER_NO_INTERACTION'])->toBe('1');
});

test('prepared shell command exports the no-colour env before the real command', function () {
    $wrapped = (new ServerlessBuildHostTools)->prepareShellCommand('composer install --no-dev');

    expect($wrapped)->toContain('export NO_COLOR=1;');
    expect($wrapped)->toContain('export TERM=dumb;');
    // The exports have to precede the command, or the tool it configures has
    // already decided to colour its output.
    expect(strpos($wrapped, 'export NO_COLOR=1;'))->toBeLessThan(strpos($wrapped, 'composer install --no-dev'));
});

test('a real composer run through the prepared command produces no escape codes', function () {
    $tools = new ServerlessBuildHostTools;
    if ($tools->findComposer() === null) {
        $this->markTestSkipped('composer is not available on this machine');
    }

    $process = Process::fromShellCommandline(
        $tools->prepareShellCommand('composer --version'),
        sys_get_temp_dir(),
        $tools->processEnv(),
    );
    $process->setTimeout(60);
    $process->run();

    expect($process->getOutput())->not->toContain("\e");
});
