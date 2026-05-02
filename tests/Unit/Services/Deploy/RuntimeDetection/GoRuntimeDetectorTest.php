<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\RuntimeDetection;

use App\Services\Deploy\RuntimeDetection\GoRuntimeDetector;
use PHPUnit\Framework\TestCase;

class GoRuntimeDetectorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/dply-go-detector-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    public function test_runtime_method_returns_go(): void
    {
        $this->assertSame('go', (new GoRuntimeDetector)->runtime());
    }

    public function test_returns_null_when_no_go_mod(): void
    {
        $this->assertNull((new GoRuntimeDetector)->detect($this->tempDir));
    }

    public function test_minimal_go_mod_yields_go_runtime_with_medium_confidence(): void
    {
        file_put_contents(
            $this->tempDir.'/go.mod',
            "module example.com/app\n\ngo 1.22\n",
        );

        $result = (new GoRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('go', $result->runtime);
        $this->assertSame('go', $result->framework);
        $this->assertSame('medium', $result->confidence);
        $this->assertSame('1.22', $result->version);
        $this->assertSame(8080, $result->appPort);
        $this->assertSame([], $result->processes);
    }

    public function test_pins_version_from_tool_versions_first(): void
    {
        file_put_contents(
            $this->tempDir.'/go.mod',
            "module example.com/app\n\ngo 1.20\n",
        );
        file_put_contents($this->tempDir.'/.tool-versions', "golang 1.23.2\n");

        $result = (new GoRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('1.23.2', $result->version);
        $this->assertContains('.tool-versions', $result->detectedFiles);
    }

    public function test_tool_versions_accepts_go_or_golang_plugin_name(): void
    {
        file_put_contents($this->tempDir.'/go.mod', "module x\n");
        file_put_contents($this->tempDir.'/.tool-versions', "go 1.22.0\n");

        $result = (new GoRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('1.22.0', $result->version);
    }

    public function test_falls_back_to_go_mod_directive(): void
    {
        file_put_contents(
            $this->tempDir.'/go.mod',
            "module example.com/app\n\ngo 1.21\n",
        );

        $result = (new GoRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('1.21', $result->version);
    }

    public function test_detects_gin_framework(): void
    {
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

        $this->assertNotNull($result);
        $this->assertSame('gin', $result->framework);
        $this->assertSame('high', $result->confidence);
    }

    public function test_detects_echo_framework_with_versioned_path(): void
    {
        file_put_contents(
            $this->tempDir.'/go.mod',
            <<<'GO'
            module example.com/app

            go 1.22

            require github.com/labstack/echo/v4 v4.11.0
            GO,
        );

        $result = (new GoRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('echo', $result->framework);
    }

    public function test_detects_fiber_framework(): void
    {
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

        $this->assertNotNull($result);
        $this->assertSame('fiber', $result->framework);
    }

    public function test_detects_chi_framework(): void
    {
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

        $this->assertNotNull($result);
        $this->assertSame('chi', $result->framework);
    }

    public function test_uses_cmd_layout_entrypoint_when_present(): void
    {
        file_put_contents($this->tempDir.'/go.mod', "module example.com/app\n");
        mkdir($this->tempDir.'/cmd/server', 0o755, true);
        file_put_contents($this->tempDir.'/cmd/server/main.go', "package main\nfunc main() {}\n");

        $result = (new GoRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('go build -o bin/server ./cmd/server', $result->buildCommand);
        $this->assertSame('./bin/server', $result->startCommand);
        $this->assertContains('cmd/server/main.go', $result->detectedFiles);
    }

    public function test_uses_root_main_go_when_no_cmd_dir(): void
    {
        file_put_contents($this->tempDir.'/go.mod', "module example.com/app\n");
        file_put_contents($this->tempDir.'/main.go', "package main\nfunc main() {}\n");

        $result = (new GoRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('go build -o bin/app .', $result->buildCommand);
        $this->assertSame('./bin/app', $result->startCommand);
        $this->assertContains('main.go', $result->detectedFiles);
    }

    public function test_falls_back_to_dot_dot_dot_when_no_main_detected(): void
    {
        file_put_contents($this->tempDir.'/go.mod', "module example.com/app\n");

        $result = (new GoRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('go build -o bin/app ./...', $result->buildCommand);
    }

    public function test_reasons_describe_each_inference(): void
    {
        file_put_contents(
            $this->tempDir.'/go.mod',
            "module example.com/app\n\ngo 1.22\n\nrequire github.com/gin-gonic/gin v1.9.1\n",
        );
        mkdir($this->tempDir.'/cmd/api', 0o755, true);
        file_put_contents($this->tempDir.'/cmd/api/main.go', '');

        $result = (new GoRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $combined = implode("\n", $result->reasons);
        $this->assertStringContainsString('go.mod', $combined);
        $this->assertStringContainsString('gin', $combined);
        $this->assertStringContainsString('cmd/api/main.go', $combined);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
