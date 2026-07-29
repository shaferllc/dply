<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\RuntimeDetection\StaticRuntimeDetectorTest;

use App\Modules\Deploy\Services\RuntimeDetection\StaticRuntimeDetector;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/dply-static-detector-'.uniqid();
    mkdir($this->tempDir);
});
afterEach(function () {
    removeDir($this->tempDir);
});
test('runtime method returns static', function () {
    expect((new StaticRuntimeDetector)->runtime())->toBe('static');
});
test('returns null when no static signals', function () {
    expect((new StaticRuntimeDetector)->detect($this->tempDir))->toBeNull();
});
test('plain index html yields static runtime with medium confidence', function () {
    file_put_contents($this->tempDir.'/index.html', '<html></html>');

    $result = (new StaticRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->runtime)->toBe('static');
    expect($result->framework)->toBe('static');
    expect($result->confidence)->toBe('medium');
    expect($result->buildCommand)->toBeNull();
    expect($result->startCommand)->toBeNull();
    expect($result->appPort)->toBeNull();
    expect($result->detectedFiles)->toContain('index.html');
});
test('detects jekyll from config yml', function () {
    file_put_contents(
        $this->tempDir.'/_config.yml',
        "title: My Blog\ntheme: minima\n",
    );

    $result = (new StaticRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('jekyll');
    expect($result->confidence)->toBe('high');
    expect($result->buildCommand)->toBe('bundle exec jekyll build');
    expect($result->outputDirectory)->toBe('_site');
    expect($result->startCommand)->toBeNull();
});
test('detects hugo from hugo toml', function () {
    file_put_contents(
        $this->tempDir.'/hugo.toml',
        "baseURL = \"https://example.com\"\n",
    );

    $result = (new StaticRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('hugo');
    expect($result->buildCommand)->toBe('hugo --minify');
    expect($result->outputDirectory)->toBe('public');
});
test('detects hugo from config toml with hugo keys', function () {
    file_put_contents(
        $this->tempDir.'/config.toml',
        "baseURL = \"https://example.com\"\ntheme = \"ananke\"\n",
    );

    $result = (new StaticRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('hugo');
});
test('does not detect hugo from unrelated config toml', function () {
    file_put_contents(
        $this->tempDir.'/config.toml',
        "[some_app]\nport = 3000\n",
    );

    $result = (new StaticRuntimeDetector)->detect($this->tempDir);

    // No `index.html` and no Hugo signals — nothing to report.
    expect($result)->toBeNull();
});
test('detects eleventy from dotted config', function () {
    file_put_contents(
        $this->tempDir.'/.eleventy.js',
        "module.exports = function() {};\n",
    );

    $result = (new StaticRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('eleventy');
    expect($result->buildCommand)->toBe('npx @11ty/eleventy');
    expect($result->outputDirectory)->toBe('_site');
});
test('detects eleventy output directory from config', function () {
    file_put_contents(
        $this->tempDir.'/eleventy.config.js',
        "export default { dir: { output: 'build' } };\n",
    );

    $result = (new StaticRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->outputDirectory)->toBe('build');
});
test('detects eleventy from modern config filenames', function () {
    file_put_contents(
        $this->tempDir.'/eleventy.config.mjs',
        "export default {};\n",
    );

    $result = (new StaticRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('eleventy');
});
test('framework wins over plain index html', function () {
    file_put_contents($this->tempDir.'/_config.yml', "title: Hi\n");
    file_put_contents($this->tempDir.'/index.html', '<html></html>');

    $result = (new StaticRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('jekyll');
    expect($result->confidence)->toBe('high');
});
test('reasons describe each inference', function () {
    file_put_contents(
        $this->tempDir.'/hugo.toml',
        "baseURL = \"https://example.com\"\n",
    );

    $result = (new StaticRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    $combined = implode("\n", $result->reasons);
    $this->assertStringContainsString('hugo.toml', $combined);
    $this->assertStringContainsString('hugo', $combined);
    $this->assertStringContainsString('build', $combined);
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
