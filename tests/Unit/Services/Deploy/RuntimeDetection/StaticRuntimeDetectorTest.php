<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\RuntimeDetection;

use App\Services\Deploy\RuntimeDetection\StaticRuntimeDetector;
use PHPUnit\Framework\TestCase;

class StaticRuntimeDetectorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/dply-static-detector-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    public function test_runtime_method_returns_static(): void
    {
        $this->assertSame('static', (new StaticRuntimeDetector)->runtime());
    }

    public function test_returns_null_when_no_static_signals(): void
    {
        $this->assertNull((new StaticRuntimeDetector)->detect($this->tempDir));
    }

    public function test_plain_index_html_yields_static_runtime_with_medium_confidence(): void
    {
        file_put_contents($this->tempDir.'/index.html', '<html></html>');

        $result = (new StaticRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('static', $result->runtime);
        $this->assertSame('static', $result->framework);
        $this->assertSame('medium', $result->confidence);
        $this->assertNull($result->buildCommand);
        $this->assertNull($result->startCommand);
        $this->assertNull($result->appPort);
        $this->assertContains('index.html', $result->detectedFiles);
    }

    public function test_detects_jekyll_from_config_yml(): void
    {
        file_put_contents(
            $this->tempDir.'/_config.yml',
            "title: My Blog\ntheme: minima\n",
        );

        $result = (new StaticRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('jekyll', $result->framework);
        $this->assertSame('high', $result->confidence);
        $this->assertSame('bundle exec jekyll build', $result->buildCommand);
        $this->assertNull($result->startCommand);
    }

    public function test_detects_hugo_from_hugo_toml(): void
    {
        file_put_contents(
            $this->tempDir.'/hugo.toml',
            "baseURL = \"https://example.com\"\n",
        );

        $result = (new StaticRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('hugo', $result->framework);
        $this->assertSame('hugo --minify', $result->buildCommand);
    }

    public function test_detects_hugo_from_config_toml_with_hugo_keys(): void
    {
        file_put_contents(
            $this->tempDir.'/config.toml',
            "baseURL = \"https://example.com\"\ntheme = \"ananke\"\n",
        );

        $result = (new StaticRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('hugo', $result->framework);
    }

    public function test_does_not_detect_hugo_from_unrelated_config_toml(): void
    {
        file_put_contents(
            $this->tempDir.'/config.toml',
            "[some_app]\nport = 3000\n",
        );

        $result = (new StaticRuntimeDetector)->detect($this->tempDir);

        // No `index.html` and no Hugo signals — nothing to report.
        $this->assertNull($result);
    }

    public function test_detects_eleventy_from_dotted_config(): void
    {
        file_put_contents(
            $this->tempDir.'/.eleventy.js',
            "module.exports = function() {};\n",
        );

        $result = (new StaticRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('eleventy', $result->framework);
        $this->assertSame('npx @11ty/eleventy', $result->buildCommand);
    }

    public function test_detects_eleventy_from_modern_config_filenames(): void
    {
        file_put_contents(
            $this->tempDir.'/eleventy.config.mjs',
            "export default {};\n",
        );

        $result = (new StaticRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('eleventy', $result->framework);
    }

    public function test_framework_wins_over_plain_index_html(): void
    {
        file_put_contents($this->tempDir.'/_config.yml', "title: Hi\n");
        file_put_contents($this->tempDir.'/index.html', '<html></html>');

        $result = (new StaticRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('jekyll', $result->framework);
        $this->assertSame('high', $result->confidence);
    }

    public function test_reasons_describe_each_inference(): void
    {
        file_put_contents(
            $this->tempDir.'/hugo.toml',
            "baseURL = \"https://example.com\"\n",
        );

        $result = (new StaticRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $combined = implode("\n", $result->reasons);
        $this->assertStringContainsString('hugo.toml', $combined);
        $this->assertStringContainsString('hugo', $combined);
        $this->assertStringContainsString('build', $combined);
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
