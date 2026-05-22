<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Deploy\RuntimeDetection;

use App\Models\SiteProcess;
use App\Services\Deploy\RuntimeDetection\PhpRuntimeDetector;
use PHPUnit\Framework\TestCase;

class PhpRuntimeDetectorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/dply-php-detector-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    public function test_runtime_method_returns_php(): void
    {
        $this->assertSame('php', (new PhpRuntimeDetector)->runtime());
    }

    public function test_returns_null_when_no_composer_json(): void
    {
        $this->assertNull((new PhpRuntimeDetector)->detect($this->tempDir));
    }

    public function test_returns_null_when_composer_json_invalid(): void
    {
        file_put_contents($this->tempDir.'/composer.json', 'not json');

        $this->assertNull((new PhpRuntimeDetector)->detect($this->tempDir));
    }

    public function test_minimal_composer_json_yields_php_runtime_with_medium_confidence(): void
    {
        $this->writeComposerJson(['name' => 'me/app']);

        $result = (new PhpRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('php', $result->runtime);
        $this->assertSame('php', $result->framework);
        $this->assertSame('medium', $result->confidence);
        $this->assertSame('composer install --no-dev --optimize-autoloader', $result->buildCommand);
        $this->assertNull($result->startCommand);
        $this->assertNull($result->appPort);
    }

    public function test_pins_version_from_tool_versions_first(): void
    {
        $this->writeComposerJson([
            'config' => ['platform' => ['php' => '8.2']],
            'require' => ['php' => '^8.1'],
        ]);
        file_put_contents($this->tempDir.'/.tool-versions', "php 8.4.1\nnode 20\n");

        $result = (new PhpRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('8.4.1', $result->version);
        $this->assertContains('.tool-versions', $result->detectedFiles);
    }

    public function test_falls_back_to_config_platform_php(): void
    {
        $this->writeComposerJson([
            'config' => ['platform' => ['php' => '8.3']],
            'require' => ['php' => '^8.1'],
        ]);

        $result = (new PhpRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('8.3', $result->version);
    }

    public function test_falls_back_to_require_php(): void
    {
        $this->writeComposerJson([
            'require' => ['php' => '^8.3'],
        ]);

        $result = (new PhpRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('^8.3', $result->version);
    }

    public function test_detects_laravel_framework_with_high_confidence(): void
    {
        $this->writeComposerJson([
            'require' => [
                'php' => '^8.3',
                'laravel/framework' => '^11.0',
            ],
        ]);

        $result = (new PhpRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('laravel', $result->framework);
        $this->assertSame('high', $result->confidence);
    }

    public function test_detects_symfony_framework(): void
    {
        $this->writeComposerJson([
            'require' => [
                'php' => '^8.2',
                'symfony/framework-bundle' => '^7.0',
            ],
        ]);

        $result = (new PhpRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('symfony', $result->framework);
    }

    public function test_detects_wordpress_from_bedrock_composer_dep(): void
    {
        $this->writeComposerJson([
            'require' => [
                'php' => '^8.2',
                'roots/wordpress' => '^6.4',
            ],
        ]);

        $result = (new PhpRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('wordpress', $result->framework);
    }

    public function test_detects_wordpress_from_wp_config_when_composer_silent(): void
    {
        $this->writeComposerJson(['require' => ['php' => '^8.2']]);
        file_put_contents($this->tempDir.'/wp-config.php', "<?php\n");

        $result = (new PhpRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame('wordpress', $result->framework);
        $this->assertContains('wp-config.php', $result->detectedFiles);
    }

    public function test_suggests_horizon_worker_when_dep_and_config_present(): void
    {
        $this->writeComposerJson([
            'require' => [
                'laravel/framework' => '^11.0',
                'laravel/horizon' => '^5.0',
            ],
        ]);
        mkdir($this->tempDir.'/config');
        file_put_contents($this->tempDir.'/config/horizon.php', "<?php\nreturn [];\n");

        $result = (new PhpRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertCount(1, $result->processes);
        $process = $result->processes[0];
        $this->assertSame(SiteProcess::TYPE_WORKER, $process->type);
        $this->assertSame('horizon', $process->name);
        $this->assertSame('php artisan horizon', $process->command);
        $this->assertContains('config/horizon.php', $result->detectedFiles);
    }

    public function test_does_not_suggest_horizon_worker_with_only_dep(): void
    {
        $this->writeComposerJson([
            'require' => [
                'laravel/framework' => '^11.0',
                'laravel/horizon' => '^5.0',
            ],
        ]);

        $result = (new PhpRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame([], $result->processes);
    }

    public function test_does_not_suggest_horizon_worker_with_only_config(): void
    {
        $this->writeComposerJson([
            'require' => ['laravel/framework' => '^11.0'],
        ]);
        mkdir($this->tempDir.'/config');
        file_put_contents($this->tempDir.'/config/horizon.php', '');

        $result = (new PhpRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $this->assertSame([], $result->processes);
    }

    public function test_reasons_describe_each_inference(): void
    {
        $this->writeComposerJson([
            'config' => ['platform' => ['php' => '8.3']],
            'require' => [
                'laravel/framework' => '^11.0',
                'laravel/horizon' => '^5.0',
            ],
        ]);
        mkdir($this->tempDir.'/config');
        file_put_contents($this->tempDir.'/config/horizon.php', '');

        $result = (new PhpRuntimeDetector)->detect($this->tempDir);

        $this->assertNotNull($result);
        $combined = implode("\n", $result->reasons);
        $this->assertStringContainsString('composer.json', $combined);
        $this->assertStringContainsString('platform.php', $combined);
        $this->assertStringContainsString('laravel', $combined);
        $this->assertStringContainsString('Horizon', $combined);
    }

    /**
     * @param  array<string, mixed>  $contents
     */
    private function writeComposerJson(array $contents): void
    {
        file_put_contents(
            $this->tempDir.'/composer.json',
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
