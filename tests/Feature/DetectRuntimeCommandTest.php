<?php

declare(strict_types=1);

namespace Tests\Feature\DetectRuntimeCommandTest;
use Illuminate\Support\Facades\Artisan;
beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/dply-detect-runtime-cli-'.uniqid();
    mkdir($this->tempDir);
});
afterEach(function () {
    removeDir($this->tempDir);
});
test('command prints human readable plan for a laravel repo', function () {
    file_put_contents(
        $this->tempDir.'/composer.json',
        json_encode(['require' => ['laravel/framework' => '^11.0']]),
    );

    $exit = Artisan::call('dply:detect-runtime', ['path' => $this->tempDir]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('Runtime plan for', $output);

    // Tabular rows include the runtime, framework, and build command.
    $this->assertStringContainsString('php', $output);
    $this->assertStringContainsString('laravel', $output);
    $this->assertStringContainsString('composer install --no-dev --optimize-autoloader', $output);
    $this->assertStringContainsString('detection', $output);
});
test('command outputs machine readable json with flag', function () {
    file_put_contents(
        $this->tempDir.'/composer.json',
        json_encode(['require' => ['laravel/framework' => '^11.0']]),
    );

    $exit = Artisan::call('dply:detect-runtime', [
        'path' => $this->tempDir,
        '--json' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    expect($decoded)->toBeArray();
    expect($decoded)->toHaveKey('plan');
    expect($decoded['plan']['runtime'])->toBe('php');
    expect($decoded['plan']['framework'])->toBe('laravel');
    expect($decoded['plan']['sources']['runtime'])->toBe('detection');
    expect($decoded['plan']['has_manifest'])->toBeFalse();
});
test('command reports when no runtime detected', function () {
    $exit = Artisan::call('dply:detect-runtime', ['path' => $this->tempDir]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('No runtime detected', $output);
});
test('command json mode emits null plan when no detection', function () {
    $exit = Artisan::call('dply:detect-runtime', [
        'path' => $this->tempDir,
        '--json' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $decoded = json_decode($output, true);
    expect($decoded)->toBeArray();
    expect($decoded['plan'])->toBeNull();
});
test('command fails when path is not a directory', function () {
    $exit = Artisan::call('dply:detect-runtime', [
        'path' => '/nonexistent/path/should-not-exist',
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    $this->assertStringContainsString('not a directory', $output);
});
test('command surfaces manifest provenance for pinned runtime', function () {
    file_put_contents(
        $this->tempDir.'/composer.json',
        json_encode(['require' => ['laravel/framework' => '^11.0']]),
    );
    file_put_contents($this->tempDir.'/dply.yaml', "runtime: php\nversion: \"8.4\"\n");

    $exit = Artisan::call('dply:detect-runtime', ['path' => $this->tempDir]);
    $output = Artisan::output();

    expect($exit)->toBe(0);
    $this->assertStringContainsString('manifest', $output);
    $this->assertStringContainsString('Runtime pinned to `php` by `dply.yaml`', $output);
    $this->assertStringContainsString('8.4', $output);
});
function removeDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir.'/'.$entry;
        is_dir($path) ? removeDir($path) : @unlink($path);
    }
    @rmdir($dir);
}
