<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\RuntimeDetection\GoRuntimeDetectorTest;

use App\Services\Deploy\RuntimeDetection\GoRuntimeDetector;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/dply-go-detector-'.uniqid();
    mkdir($this->tempDir);
});
afterEach(function () {
    removeDir($this->tempDir);
});
test('runtime method returns go', function () {
    expect((new GoRuntimeDetector)->runtime())->toBe('go');
});
test('returns null when no go mod', function () {
    expect((new GoRuntimeDetector)->detect($this->tempDir))->toBeNull();
});
test('minimal go mod yields go runtime with medium confidence', function () {
    file_put_contents(
        $this->tempDir.'/go.mod',
        "module example.com/app\n\ngo 1.22\n",
    );

    $result = (new GoRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->runtime)->toBe('go');
    expect($result->framework)->toBe('go');
    expect($result->confidence)->toBe('medium');
    expect($result->version)->toBe('1.22');
    expect($result->appPort)->toBe(8080);
    expect($result->processes)->toBe([]);
});
test('pins version from tool versions first', function () {
    file_put_contents(
        $this->tempDir.'/go.mod',
        "module example.com/app\n\ngo 1.20\n",
    );
    file_put_contents($this->tempDir.'/.tool-versions', "golang 1.23.2\n");

    $result = (new GoRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->version)->toBe('1.23.2');
    expect($result->detectedFiles)->toContain('.tool-versions');
});
test('tool versions accepts go or golang plugin name', function () {
    file_put_contents($this->tempDir.'/go.mod', "module x\n");
    file_put_contents($this->tempDir.'/.tool-versions', "go 1.22.0\n");

    $result = (new GoRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->version)->toBe('1.22.0');
});
test('falls back to go mod directive', function () {
    file_put_contents(
        $this->tempDir.'/go.mod',
        "module example.com/app\n\ngo 1.21\n",
    );

    $result = (new GoRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->version)->toBe('1.21');
});
test('detects gin framework', function () {
    file_put_contents(
        $this->tempDir.'/go.mod',
        <<<'GO'
            module example.com/app

            go 1.22

            require (
                github.com/gin-gonic/gin v1.9.1
                github.com/stretchr/testify v1.8.4
            )
            GO,
    );

    $result = (new GoRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('gin');
    expect($result->confidence)->toBe('high');
});
test('detects echo framework with versioned path', function () {
    file_put_contents(
        $this->tempDir.'/go.mod',
        <<<'GO'
            module example.com/app

            go 1.22

            require github.com/labstack/echo/v4 v4.11.0
            GO,
    );

    $result = (new GoRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('echo');
});
test('detects fiber framework', function () {
    file_put_contents(
        $this->tempDir.'/go.mod',
        <<<'GO'
            module example.com/app

            go 1.22

            require (
                github.com/gofiber/fiber/v2 v2.52.0
            )
            GO,
    );

    $result = (new GoRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('fiber');
});
test('detects chi framework', function () {
    file_put_contents(
        $this->tempDir.'/go.mod',
        <<<'GO'
            module example.com/app

            go 1.22

            require (
                github.com/go-chi/chi/v5 v5.0.10
            )
            GO,
    );

    $result = (new GoRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->framework)->toBe('chi');
});
test('uses cmd layout entrypoint when present', function () {
    file_put_contents($this->tempDir.'/go.mod', "module example.com/app\n");
    mkdir($this->tempDir.'/cmd/server', 0o755, true);
    file_put_contents($this->tempDir.'/cmd/server/main.go', "package main\nfunc main() {}\n");

    $result = (new GoRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->buildCommand)->toBe('go build -o bin/server ./cmd/server');
    expect($result->startCommand)->toBe('./bin/server');
    expect($result->detectedFiles)->toContain('cmd/server/main.go');
});
test('uses root main go when no cmd dir', function () {
    file_put_contents($this->tempDir.'/go.mod', "module example.com/app\n");
    file_put_contents($this->tempDir.'/main.go', "package main\nfunc main() {}\n");

    $result = (new GoRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->buildCommand)->toBe('go build -o bin/app .');
    expect($result->startCommand)->toBe('./bin/app');
    expect($result->detectedFiles)->toContain('main.go');
});
test('falls back to dot dot dot when no main detected', function () {
    file_put_contents($this->tempDir.'/go.mod', "module example.com/app\n");

    $result = (new GoRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    expect($result->buildCommand)->toBe('go build -o bin/app ./...');
});
test('reasons describe each inference', function () {
    file_put_contents(
        $this->tempDir.'/go.mod',
        "module example.com/app\n\ngo 1.22\n\nrequire github.com/gin-gonic/gin v1.9.1\n",
    );
    mkdir($this->tempDir.'/cmd/api', 0o755, true);
    file_put_contents($this->tempDir.'/cmd/api/main.go', '');

    $result = (new GoRuntimeDetector)->detect($this->tempDir);

    expect($result)->not->toBeNull();
    $combined = implode("\n", $result->reasons);
    $this->assertStringContainsString('go.mod', $combined);
    $this->assertStringContainsString('gin', $combined);
    $this->assertStringContainsString('cmd/api/main.go', $combined);
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
