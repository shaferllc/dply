<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Deploy\ServerlessRuntimeDetector;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ServerlessRuntimeDetectorTest extends TestCase
{
    public function test_it_detects_laravel_and_marks_it_unsupported_for_digitalocean_functions(): void
    {
        $path = storage_path('framework/testing/runtime-detector-laravel-'.uniqid());
        File::ensureDirectoryExists($path.'/bootstrap');
        File::ensureDirectoryExists($path.'/routes');
        File::ensureDirectoryExists($path.'/public');
        File::put($path.'/artisan', "#!/usr/bin/env php\n");
        File::put($path.'/bootstrap/app.php', "<?php\n");
        File::put($path.'/routes/web.php', "<?php\n");
        File::put($path.'/public/index.php', "<?php\n");
        File::put($path.'/composer.json', json_encode([
            'require' => [
                'laravel/framework' => '^11.0',
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $result = app(ServerlessRuntimeDetector::class)->detect($path, [
            'supports_php_runtime' => false,
            'supports_node_runtime' => true,
            'default_runtime' => 'nodejs:18',
            'default_entrypoint' => 'index',
            'default_package' => 'default',
        ]);

        $this->assertSame('laravel', $result['framework']);
        $this->assertSame('php', $result['language']);
        $this->assertTrue($result['unsupported_for_target']);
        $this->assertNotEmpty($result['warnings']);

        File::deleteDirectory($path);
    }

    public function test_it_detects_a_node_build_from_package_json(): void
    {
        $path = storage_path('framework/testing/runtime-detector-node-'.uniqid());
        File::ensureDirectoryExists($path);
        File::put($path.'/package.json', json_encode([
            'scripts' => [
                'build' => 'vite build',
            ],
            'dependencies' => [
                'vite' => '^5.0.0',
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $result = app(ServerlessRuntimeDetector::class)->detect($path, [
            'supports_php_runtime' => false,
            'supports_node_runtime' => true,
            'default_runtime' => 'nodejs:18',
            'default_entrypoint' => 'index',
            'default_package' => 'default',
        ]);

        $this->assertSame('vite_static', $result['framework']);
        $this->assertSame('node', $result['language']);
        $this->assertSame('nodejs:18', $result['runtime']);
        $this->assertSame('npm install && npm run build', $result['build_command']);
        $this->assertSame('dist', $result['artifact_output_path']);
        $this->assertFalse($result['unsupported_for_target']);

        File::deleteDirectory($path);
    }

    public function test_it_detects_laravel_and_marks_it_supported_for_aws_lambda(): void
    {
        $path = storage_path('framework/testing/runtime-detector-laravel-aws-'.uniqid());
        File::ensureDirectoryExists($path.'/bootstrap');
        File::ensureDirectoryExists($path.'/routes');
        File::ensureDirectoryExists($path.'/public');
        File::put($path.'/artisan', "#!/usr/bin/env php\n");
        File::put($path.'/bootstrap/app.php', "<?php\n");
        File::put($path.'/routes/web.php', "<?php\n");
        File::put($path.'/public/index.php', "<?php\n");
        File::put($path.'/composer.json', json_encode([
            'require' => [
                'laravel/framework' => '^12.0',
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $result = app(ServerlessRuntimeDetector::class)->detect($path, [
            'supports_php_runtime' => true,
            'supports_node_runtime' => true,
            'default_runtime' => 'provided.al2023',
            'default_entrypoint' => 'public/index.php',
            'default_package' => '',
        ]);

        $this->assertSame('laravel', $result['framework']);
        $this->assertSame('php', $result['language']);
        $this->assertSame('provided.al2023', $result['runtime']);
        $this->assertSame('public/index.php', $result['entrypoint']);
        $this->assertFalse($result['unsupported_for_target']);

        File::deleteDirectory($path);
    }
}
