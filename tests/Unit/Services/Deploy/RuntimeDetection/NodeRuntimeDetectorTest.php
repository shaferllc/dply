<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\RuntimeDetection\NodeRuntimeDetectorTest;
use App\Models\SiteProcess;
use App\Services\Deploy\RuntimeDetection\NodeRuntimeDetector;
beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/dply-node-detector-'.uniqid();
    mkdir($this->tempDir);
});
afterEach(function () {
    removeDir($this->tempDir);
});
test('returns null when no package json', function () {
    $result = (new NodeRuntimeDetector)->detect($this->tempDir);

    expect($result)->toBeNull();
});
test('returns null when package json is invalid json', function () {
    file_put_contents($this->tempDir.'/package.json', 'not json');

    $result = (new NodeRuntimeDetector)->detect($this->tempDir);

    expect($result)->toBeNull();
});
test('minimal package json yields node runtime with medium confidence', function () {
    writePackageJson($this->tempDir, ['name' => 'tiny']);

    $result = (new NodeRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->runtime)->toBe('node');
    expect($result->framework)->toBe('node');
    expect($result->version)->toBeNull();
    expect($result->buildCommand)->toBeNull();
    expect($result->startCommand)->toBeNull();
    expect($result->appPort)->toBe(3000);
    expect($result->confidence)->toBe('medium');
    expect($result->detectedFiles)->toContain('package.json');
});
test('pins version from tool versions first', function () {
    writePackageJson($this->tempDir, ['engines' => ['node' => '>=18']]);
    file_put_contents($this->tempDir.'/.tool-versions', "node 22.7.0\npython 3.13.0\n");
    file_put_contents($this->tempDir.'/.nvmrc', "20\n");

    $result = (new NodeRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->version)->toBe('22.7.0');
    expect($result->detectedFiles)->toContain('.tool-versions');
    expect($result->detectedFiles)->not->toContain('.nvmrc');
});
test('falls back to nvmrc when no tool versions', function () {
    writePackageJson($this->tempDir, ['engines' => ['node' => '>=18']]);
    file_put_contents($this->tempDir.'/.nvmrc', "v20.10.0\n");

    $result = (new NodeRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->version)->toBe('20.10.0', 'leading v should be stripped');
});
test('falls back to engines node when no pin files', function () {
    writePackageJson($this->tempDir, ['engines' => ['node' => '^22.0.0']]);

    $result = (new NodeRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->version)->toBe('^22.0.0');
});
test('detects next framework with high confidence', function () {
    writePackageJson($this->tempDir, [
        'dependencies' => ['next' => '^14.0.0', 'react' => '^18.0.0'],
        'scripts' => ['build' => 'next build', 'start' => 'next start'],
    ]);

    $result = (new NodeRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('next');
    expect($result->confidence)->toBe('high');
    expect($result->buildCommand)->toBe('npm run build');
    expect($result->startCommand)->toBe('npm start');
});
test('detects nuxt framework', function () {
    writePackageJson($this->tempDir, ['dependencies' => ['nuxt' => '^3.0.0']]);

    $result = (new NodeRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('nuxt');
});
test('detects nest framework from nestjs core', function () {
    writePackageJson($this->tempDir, ['dependencies' => ['@nestjs/core' => '^10.0.0']]);

    $result = (new NodeRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('nest');
});
test('uses main when no start script', function () {
    writePackageJson($this->tempDir, [
        'main' => 'server.js',
    ]);

    $result = (new NodeRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->startCommand)->toBe('node server.js');
});
test('extracts explicit port from start script', function () {
    writePackageJson($this->tempDir, [
        'scripts' => ['start' => 'node server.js --port=4000'],
    ]);

    $result = (new NodeRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->appPort)->toBe(4000);
});
test('extracts port from env var in dev script', function () {
    writePackageJson($this->tempDir, [
        'scripts' => ['dev' => 'PORT=4321 next dev'],
    ]);

    $result = (new NodeRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->appPort)->toBe(4321);
});
test('suggests bullmq worker process', function () {
    writePackageJson($this->tempDir, [
        'dependencies' => ['bullmq' => '^5.0.0'],
        'scripts' => ['start' => 'node server.js', 'worker' => 'node worker.js'],
    ]);

    $result = (new NodeRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->processes)->toHaveCount(1);
    $process = $result->processes[0];
    expect($process->type)->toBe(SiteProcess::TYPE_WORKER);
    expect($process->name)->toBe('worker');
    expect($process->command)->toBe('npm run worker');
    $this->assertStringContainsString('BullMQ', $process->reason);
});
test('does not suggest worker when only bullmq dep', function () {
    writePackageJson($this->tempDir, [
        'dependencies' => ['bullmq' => '^5.0.0'],
        'scripts' => ['start' => 'node server.js'],
    ]);

    $result = (new NodeRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->processes)->toBe([]);
});
test('does not suggest worker when only worker script', function () {
    writePackageJson($this->tempDir, [
        'scripts' => ['start' => 'node server.js', 'worker' => 'node worker.js'],
    ]);

    $result = (new NodeRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->processes)->toBe([]);
});
test('runtime method returns node', function () {
    expect((new NodeRuntimeDetector)->runtime())->toBe('node');
});
test('reasons describe each inference', function () {
    writePackageJson($this->tempDir, [
        'engines' => ['node' => '20'],
        'dependencies' => ['next' => '^14.0.0'],
        'scripts' => ['build' => 'next build', 'start' => 'next start'],
    ]);

    $result = (new NodeRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    $combinedReasons = implode("\n", $result->reasons);
    $this->assertStringContainsString('package.json', $combinedReasons);
    $this->assertStringContainsString('engines.node', $combinedReasons);
    $this->assertStringContainsString('next', $combinedReasons);
    $this->assertStringContainsString('build', $combinedReasons);
    $this->assertStringContainsString('start', $combinedReasons);
});
/**
 * @param  array<string, mixed>  $contents
 */
function writePackageJson(string $dir, array $contents): void
{
    file_put_contents(
        $dir.'/package.json',
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
