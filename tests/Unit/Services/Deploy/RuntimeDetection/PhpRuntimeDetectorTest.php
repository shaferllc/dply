<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\RuntimeDetection\PhpRuntimeDetectorTest;
use App\Models\SiteProcess;
use App\Services\Deploy\RuntimeDetection\PhpRuntimeDetector;
beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/dply-php-detector-'.uniqid();
    mkdir($this->tempDir);
});
afterEach(function () {
    removeDir($this->tempDir);
});
test('runtime method returns php', function () {
    expect((new PhpRuntimeDetector)->runtime())->toBe('php');
});
test('returns null when no composer json', function () {
    expect((new PhpRuntimeDetector)->detect($this->tempDir))->toBeNull();
});
test('returns null when composer json invalid', function () {
    file_put_contents($this->tempDir.'/composer.json', 'not json');

    expect((new PhpRuntimeDetector)->detect($this->tempDir))->toBeNull();
});
test('minimal composer json yields php runtime with medium confidence', function () {
    writeComposerJson($this->tempDir, ['name' => 'me/app']);

    $result = (new PhpRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->runtime)->toBe('php');
    expect($result->framework)->toBe('php');
    expect($result->confidence)->toBe('medium');
    expect($result->buildCommand)->toBe('composer install --no-dev --optimize-autoloader');
    expect($result->startCommand)->toBeNull();
    expect($result->appPort)->toBeNull();
});
test('pins version from tool versions first', function () {
    writeComposerJson($this->tempDir, [
        'config' => ['platform' => ['php' => '8.2']],
        'require' => ['php' => '^8.1'],
    ]);
    file_put_contents($this->tempDir.'/.tool-versions', "php 8.4.1\nnode 20\n");

    $result = (new PhpRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->version)->toBe('8.4.1');
    expect($result->detectedFiles)->toContain('.tool-versions');
});
test('falls back to config platform php', function () {
    writeComposerJson($this->tempDir, [
        'config' => ['platform' => ['php' => '8.3']],
        'require' => ['php' => '^8.1'],
    ]);

    $result = (new PhpRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->version)->toBe('8.3');
});
test('falls back to require php', function () {
    writeComposerJson($this->tempDir, [
        'require' => ['php' => '^8.3'],
    ]);

    $result = (new PhpRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->version)->toBe('^8.3');
});
test('detects laravel framework with high confidence', function () {
    writeComposerJson($this->tempDir, [
        'require' => [
            'php' => '^8.3',
            'laravel/framework' => '^11.0',
        ],
    ]);

    $result = (new PhpRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('laravel');
    expect($result->confidence)->toBe('high');
});
test('detects symfony framework', function () {
    writeComposerJson($this->tempDir, [
        'require' => [
            'php' => '^8.2',
            'symfony/framework-bundle' => '^7.0',
        ],
    ]);

    $result = (new PhpRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('symfony');
});
test('detects wordpress from bedrock composer dep', function () {
    writeComposerJson($this->tempDir, [
        'require' => [
            'php' => '^8.2',
            'roots/wordpress' => '^6.4',
        ],
    ]);

    $result = (new PhpRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('wordpress');
});
test('detects wordpress from wp config when composer silent', function () {
    writeComposerJson($this->tempDir, ['require' => ['php' => '^8.2']]);
    file_put_contents($this->tempDir.'/wp-config.php', "<?php\n");

    $result = (new PhpRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('wordpress');
    expect($result->detectedFiles)->toContain('wp-config.php');
});
test('suggests horizon worker when dep and config present', function () {
    writeComposerJson($this->tempDir, [
        'require' => [
            'laravel/framework' => '^11.0',
            'laravel/horizon' => '^5.0',
        ],
    ]);
    mkdir($this->tempDir.'/config');
    file_put_contents($this->tempDir.'/config/horizon.php', "<?php\nreturn [];\n");

    $result = (new PhpRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->processes)->toHaveCount(1);
    $process = $result->processes[0];
    expect($process->type)->toBe(SiteProcess::TYPE_WORKER);
    expect($process->name)->toBe('horizon');
    expect($process->command)->toBe('php artisan horizon');
    expect($result->detectedFiles)->toContain('config/horizon.php');
});
test('does not suggest horizon worker with only dep', function () {
    writeComposerJson($this->tempDir, [
        'require' => [
            'laravel/framework' => '^11.0',
            'laravel/horizon' => '^5.0',
        ],
    ]);

    $result = (new PhpRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->processes)->toBe([]);
});
test('does not suggest horizon worker with only config', function () {
    writeComposerJson($this->tempDir, [
        'require' => ['laravel/framework' => '^11.0'],
    ]);
    mkdir($this->tempDir.'/config');
    file_put_contents($this->tempDir.'/config/horizon.php', '');

    $result = (new PhpRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->processes)->toBe([]);
});
test('reasons describe each inference', function () {
    writeComposerJson($this->tempDir, [
        'config' => ['platform' => ['php' => '8.3']],
        'require' => [
            'laravel/framework' => '^11.0',
            'laravel/horizon' => '^5.0',
        ],
    ]);
    mkdir($this->tempDir.'/config');
    file_put_contents($this->tempDir.'/config/horizon.php', '');

    $result = (new PhpRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    $combined = implode("\n", $result->reasons);
    $this->assertStringContainsString('composer.json', $combined);
    $this->assertStringContainsString('platform.php', $combined);
    $this->assertStringContainsString('laravel', $combined);
    $this->assertStringContainsString('Horizon', $combined);
});
/**
 * @param  array<string, mixed>  $contents
 */
function writeComposerJson(string $dir, array $contents): void
{
    file_put_contents(
        $dir.'/composer.json',
        json_encode($contents, JSON_PRETTY_PRINT),
    );
}
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
