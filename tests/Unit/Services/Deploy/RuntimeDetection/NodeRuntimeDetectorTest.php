<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\RuntimeDetection;

use App\Models\SiteProcess;
use App\Services\Deploy\RuntimeDetection\NodeRuntimeDetector;
use PHPUnit\Framework\TestCase;

class NodeRuntimeDetectorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/dply-node-detector-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    public function test_returns_null_when_no_package_json(): void
    {
        $result = (new NodeRuntimeDetector)->detect($this->tempDir);

        $this->assertNull($result);
    }

    public function test_returns_null_when_package_json_is_invalid_json(): void
    {
        file_put_contents($this->tempDir.'/package.json', 'not json');

        $result = (new NodeRuntimeDetector)->detect($this->tempDir);

        $this->assertNull($result);
    }

    public function test_minimal_package_json_yields_node_runtime_with_medium_confidence(): void
    {
        $this->writePackageJson(['name' => 'tiny']);

        $result = (new NodeRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('node', $result->runtime);
        $this->assertSame('node', $result->framework);
        $this->assertNull($result->version);
        $this->assertNull($result->buildCommand);
        $this->assertNull($result->startCommand);
        $this->assertSame(3000, $result->appPort);
        $this->assertSame('medium', $result->confidence);
        $this->assertContains('package.json', $result->detectedFiles);
    }

    public function test_pins_version_from_tool_versions_first(): void
    {
        $this->writePackageJson(['engines' => ['node' => '>=18']]);
        file_put_contents($this->tempDir.'/.tool-versions', "node 22.7.0\npython 3.13.0\n");
        file_put_contents($this->tempDir.'/.nvmrc', "20\n");

        $result = (new NodeRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('22.7.0', $result->version);
        $this->assertContains('.tool-versions', $result->detectedFiles);
        $this->assertNotContains('.nvmrc', $result->detectedFiles);
    }

    public function test_falls_back_to_nvmrc_when_no_tool_versions(): void
    {
        $this->writePackageJson(['engines' => ['node' => '>=18']]);
        file_put_contents($this->tempDir.'/.nvmrc', "v20.10.0\n");

        $result = (new NodeRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('20.10.0', $result->version, 'leading v should be stripped');
    }

    public function test_falls_back_to_engines_node_when_no_pin_files(): void
    {
        $this->writePackageJson(['engines' => ['node' => '^22.0.0']]);

        $result = (new NodeRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('^22.0.0', $result->version);
    }

    public function test_detects_next_framework_with_high_confidence(): void
    {
        $this->writePackageJson([
            'dependencies' => ['next' => '^14.0.0', 'react' => '^18.0.0'],
            'scripts' => ['build' => 'next build', 'start' => 'next start'],
        ]);

        $result = (new NodeRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('next', $result->framework);
        $this->assertSame('high', $result->confidence);
        $this->assertSame('npm run build', $result->buildCommand);
        $this->assertSame('npm start', $result->startCommand);
    }

    public function test_detects_nuxt_framework(): void
    {
        $this->writePackageJson(['dependencies' => ['nuxt' => '^3.0.0']]);

        $result = (new NodeRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('nuxt', $result->framework);
    }

    public function test_detects_nest_framework_from_nestjs_core(): void
    {
        $this->writePackageJson(['dependencies' => ['@nestjs/core' => '^10.0.0']]);

        $result = (new NodeRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('nest', $result->framework);
    }

    public function test_uses_main_when_no_start_script(): void
    {
        $this->writePackageJson([
            'main' => 'server.js',
        ]);

        $result = (new NodeRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('node server.js', $result->startCommand);
    }

    public function test_extracts_explicit_port_from_start_script(): void
    {
        $this->writePackageJson([
            'scripts' => ['start' => 'node server.js --port=4000'],
        ]);

        $result = (new NodeRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame(4000, $result->appPort);
    }

    public function test_extracts_port_from_env_var_in_dev_script(): void
    {
        $this->writePackageJson([
            'scripts' => ['dev' => 'PORT=4321 next dev'],
        ]);

        $result = (new NodeRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame(4321, $result->appPort);
    }

    public function test_suggests_bullmq_worker_process(): void
    {
        $this->writePackageJson([
            'dependencies' => ['bullmq' => '^5.0.0'],
            'scripts' => ['start' => 'node server.js', 'worker' => 'node worker.js'],
        ]);

        $result = (new NodeRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertCount(1, $result->processes);
        $process = $result->processes[0];
        $this->assertSame(SiteProcess::TYPE_WORKER, $process->type);
        $this->assertSame('worker', $process->name);
        $this->assertSame('npm run worker', $process->command);
        $this->assertStringContainsString('BullMQ', $process->reason);
    }

    public function test_does_not_suggest_worker_when_only_bullmq_dep(): void
    {
        $this->writePackageJson([
            'dependencies' => ['bullmq' => '^5.0.0'],
            'scripts' => ['start' => 'node server.js'],
        ]);

        $result = (new NodeRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame([], $result->processes);
    }

    public function test_does_not_suggest_worker_when_only_worker_script(): void
    {
        $this->writePackageJson([
            'scripts' => ['start' => 'node server.js', 'worker' => 'node worker.js'],
        ]);

        $result = (new NodeRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame([], $result->processes);
    }

    public function test_runtime_method_returns_node(): void
    {
        $this->assertSame('node', (new NodeRuntimeDetector)->runtime());
    }

    public function test_reasons_describe_each_inference(): void
    {
        $this->writePackageJson([
            'engines' => ['node' => '20'],
            'dependencies' => ['next' => '^14.0.0'],
            'scripts' => ['build' => 'next build', 'start' => 'next start'],
        ]);

        $result = (new NodeRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $combinedReasons = implode("\n", $result->reasons);
        $this->assertStringContainsString('package.json', $combinedReasons);
        $this->assertStringContainsString('engines.node', $combinedReasons);
        $this->assertStringContainsString('next', $combinedReasons);
        $this->assertStringContainsString('build', $combinedReasons);
        $this->assertStringContainsString('start', $combinedReasons);
    }

    /**
     * @param  array<string, mixed>  $contents
     */
    private function writePackageJson(array $contents): void
    {
        file_put_contents(
            $this->tempDir.'/package.json',
            json_encode($contents, JSON_PRETTY_PRINT),
        );
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
