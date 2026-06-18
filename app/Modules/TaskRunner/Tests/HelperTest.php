<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Helper;
use Illuminate\Support\Facades\Config;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Tests\TestCase;

uses(TestCase::class);

test('scriptInSubshell wraps script with here document', function () {
    $script = "echo 'Hello World'";
    $result = Helper::scriptInSubshell($script);

    expect($result)->toContain("'bash -s' << '")
        ->and($result)->toContain($script);
});

test('scriptInSubshell throws on empty script', function () {
    expect(fn () => Helper::scriptInSubshell(''))
        ->toThrow(InvalidArgumentException::class);
});

test('scriptInSubshell throws on too large script', function () {
    $script = str_repeat('a', 1024 * 1024 + 1);
    expect(fn () => Helper::scriptInSubshell($script))
        ->toThrow(InvalidArgumentException::class);
});

test('temporaryDirectory returns a TemporaryDirectory instance', function () {
    $dir = Helper::temporaryDirectory();
    expect($dir)->toBeInstanceOf(TemporaryDirectory::class);
    expect(is_dir($dir->path()))->toBeTrue();
    $dir->delete();
});

test('temporaryDirectory throws if directory is not writable', function () {
    Config::set('task-runner.temporary_directory', '/root/should-not-exist');
    expect(fn () => Helper::temporaryDirectory())
        ->toThrow(InvalidArgumentException::class);
    Config::set('task-runner.temporary_directory', null);
});

test('temporaryDirectoryPath returns a valid path', function () {
    $path = Helper::temporaryDirectoryPath('testfile.txt');
    expect($path)->toEndWith('testfile.txt');
    expect(dirname($path))->toBeDirectory();
});

test('temporaryDirectoryPath throws on invalid path', function () {
    $invalid = '../etc/passwd';
    expect(fn () => Helper::temporaryDirectoryPath($invalid))
        ->toThrow(InvalidArgumentException::class);
});

test('scriptInBackground returns valid command', function () {
    $script = "echo 'test'";
    $file = Helper::createSecureTempFile($script);
    $cmd = Helper::scriptInBackground($file, '/dev/null', 10);
    expect($cmd)->toContain('nohup timeout 10s bash');
    expect($cmd)->toContain($file);
    Helper::safeRemoveFile($file);
});

test('scriptInBackground throws if script file does not exist', function () {
    expect(fn () => Helper::scriptInBackground('/tmp/does-not-exist.sh'))
        ->toThrow(InvalidArgumentException::class);
});

test('scriptInBackground throws on invalid timeout', function () {
    $file = Helper::createSecureTempFile('echo test');
    expect(fn () => Helper::scriptInBackground($file, null, -1))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => Helper::scriptInBackground($file, null, 90000))
        ->toThrow(InvalidArgumentException::class);
    Helper::safeRemoveFile($file);
});

test('eof returns custom config value if set', function () {
    Config::set('task-runner.eof', 'CUSTOM-EOF');
    expect(Helper::eof('abc'))->toBe('CUSTOM-EOF');
    Config::set('task-runner.eof', null);
});

test('eof returns hash-based value if config not set', function () {
    $eof = Helper::eof('abc');
    expect($eof)->toStartWith('TASK-RUNNER-');
});

test('createSecureTempFile creates file with correct permissions', function () {
    $content = "#!/bin/bash\necho 'secure'";
    $file = Helper::createSecureTempFile($content);
    expect(file_exists($file))->toBeTrue();
    expect((fileperms($file) & 0777))->toBe(0700);
    $read = file_get_contents($file);
    expect($read)->toBe($content);
    Helper::safeRemoveFile($file);
});

test('createSecureTempFile cleans up on error', function () {
    // Simulate unwritable directory by setting config to a non-existent path
    Config::set('task-runner.temporary_directory', '/root/should-not-exist');
    expect(fn () => Helper::createSecureTempFile('fail'))
        ->toThrow(InvalidArgumentException::class, 'Temporary directory is not writable');
    Config::set('task-runner.temporary_directory', null);
});

test('safeRemoveFile removes file and returns true', function () {
    $file = Helper::createSecureTempFile('echo test');
    expect(file_exists($file))->toBeTrue();
    $result = Helper::safeRemoveFile($file);
    expect($result)->toBeTrue();
    expect(file_exists($file))->toBeFalse();
});

test('safeRemoveFile returns true if file does not exist', function () {
    $result = Helper::safeRemoveFile('/tmp/does-not-exist-'.uniqid());
    expect($result)->toBeTrue();
});

test('validateScriptContent returns script if valid', function () {
    $script = "echo 'safe'";
    $result = Helper::validateScriptContent($script);
    expect($result)->toBe($script);
});

test('validateScriptContent throws on empty', function () {
    expect(fn () => Helper::validateScriptContent(''))
        ->toThrow(InvalidArgumentException::class);
});

test('validateScriptContent throws on too large', function () {
    $script = str_repeat('a', 1024 * 1024 + 1);
    expect(fn () => Helper::validateScriptContent($script))
        ->toThrow(InvalidArgumentException::class);
});

test('validateScriptContent throws on dangerous patterns', function () {
    $dangerous = [
        '${VAR}',
        '$(ls)',
        '`rm -rf /`',
    ];
    foreach ($dangerous as $script) {
        expect(fn () => Helper::validateScriptContent($script))
            ->toThrow(InvalidArgumentException::class);
    }
});

// Additional tests for Helper edge cases
test('validatePath throws on null bytes', function () {
    $reflection = new ReflectionClass(Helper::class);
    $method = $reflection->getMethod('validatePath');
    $method->setAccessible(true);
    expect(fn () => $method->invoke(null, "/tmp/evil\0path"))
        ->toThrow(InvalidArgumentException::class, 'Path contains null bytes.');
});

test('validatePath throws on double slashes', function () {
    $reflection = new ReflectionClass(Helper::class);
    $method = $reflection->getMethod('validatePath');
    $method->setAccessible(true);
    expect(fn () => $method->invoke(null, '/tmp//evilpath'))
        ->toThrow(InvalidArgumentException::class, 'Path contains invalid characters.');
});

test('validatePath throws on overly long path', function () {
    $reflection = new ReflectionClass(Helper::class);
    $method = $reflection->getMethod('validatePath');
    $method->setAccessible(true);
    $longPath = '/tmp/'.str_repeat('a', 4097);
    expect(fn () => $method->invoke(null, $longPath))
        ->toThrow(InvalidArgumentException::class, 'Path is too long.');
});

test('scriptInBackground uses default timeout and output', function () {
    $file = Helper::createSecureTempFile('echo test');
    $cmd = Helper::scriptInBackground($file);
    expect($cmd)->toContain('nohup bash');
    expect($cmd)->toContain($file);
    expect($cmd)->toContain('/dev/null');
    Helper::safeRemoveFile($file);
});

test('createSecureTempFile uses custom extension', function () {
    $file = Helper::createSecureTempFile('echo ext', '.custom');
    expect(str_ends_with($file, '.custom'))->toBeTrue();
    Helper::safeRemoveFile($file);
});
