<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DetectRuntimeCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/dply-detect-runtime-cli-'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    public function test_command_prints_human_readable_plan_for_a_laravel_repo(): void
    {
        file_put_contents(
            $this->tempDir.'/composer.json',
            json_encode(['require' => ['laravel/framework' => '^11.0']]),
        );

        $exit = Artisan::call('dply:detect-runtime', ['path' => $this->tempDir]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Runtime plan for', $output);
        // Tabular rows include the runtime, framework, and build command.
        $this->assertStringContainsString('php', $output);
        $this->assertStringContainsString('laravel', $output);
        $this->assertStringContainsString('composer install --no-dev --optimize-autoloader', $output);
        $this->assertStringContainsString('detection', $output);
    }

    public function test_command_outputs_machine_readable_json_with_flag(): void
    {
        file_put_contents(
            $this->tempDir.'/composer.json',
            json_encode(['require' => ['laravel/framework' => '^11.0']]),
        );

        $exit = Artisan::call('dply:detect-runtime', [
            'path' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('plan', $decoded);
        $this->assertSame('php', $decoded['plan']['runtime']);
        $this->assertSame('laravel', $decoded['plan']['framework']);
        $this->assertSame('detection', $decoded['plan']['sources']['runtime']);
        $this->assertFalse($decoded['plan']['has_manifest']);
    }

    public function test_command_reports_when_no_runtime_detected(): void
    {
        $exit = Artisan::call('dply:detect-runtime', ['path' => $this->tempDir]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No runtime detected', $output);
    }

    public function test_command_json_mode_emits_null_plan_when_no_detection(): void
    {
        $exit = Artisan::call('dply:detect-runtime', [
            'path' => $this->tempDir,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertNull($decoded['plan']);
    }

    public function test_command_fails_when_path_is_not_a_directory(): void
    {
        $exit = Artisan::call('dply:detect-runtime', [
            'path' => '/nonexistent/path/should-not-exist',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not a directory', $output);
    }

    public function test_command_surfaces_manifest_provenance_for_pinned_runtime(): void
    {
        file_put_contents(
            $this->tempDir.'/composer.json',
            json_encode(['require' => ['laravel/framework' => '^11.0']]),
        );
        file_put_contents($this->tempDir.'/dply.yaml', "runtime: php\nversion: \"8.4\"\n");

        $exit = Artisan::call('dply:detect-runtime', ['path' => $this->tempDir]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('manifest', $output);
        $this->assertStringContainsString('Runtime pinned to `php` by `dply.yaml`', $output);
        $this->assertStringContainsString('8.4', $output);
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
