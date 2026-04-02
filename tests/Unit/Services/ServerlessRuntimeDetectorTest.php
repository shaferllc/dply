<?php

namespace Tests\Unit\Services;

use App\Services\Deploy\ServerlessRuntimeDetector;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ServerlessRuntimeDetectorTest extends TestCase
{
    public function test_detects_rails_when_gemfile_and_application_rb_present(): void
    {
        $dir = storage_path('framework/testing/serverless-detector-'.uniqid());
        File::makeDirectory($dir, 0755, true);
        File::makeDirectory($dir.'/config', 0755, true);
        File::put($dir.'/Gemfile', "source 'https://rubygems.org'\n");
        File::put($dir.'/config/application.rb', "module App\nend\n");

        try {
            $detector = new ServerlessRuntimeDetector;
            $result = $detector->detect($dir, [
                'supports_php_runtime' => true,
                'supports_node_runtime' => true,
                'default_runtime' => 'ruby:3.3',
                'default_entrypoint' => 'index',
                'default_package' => 'default',
            ]);

            $this->assertSame('rails', $result['framework']);
            $this->assertSame('ruby', $result['language']);
            $this->assertSame('high', $result['confidence']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_detects_symfony_via_framework_bundle(): void
    {
        $dir = storage_path('framework/testing/serverless-detector-'.uniqid());
        File::makeDirectory($dir, 0755, true);
        File::put($dir.'/composer.json', json_encode([
            'require' => [
                'symfony/framework-bundle' => '^7.0',
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $detector = new ServerlessRuntimeDetector;
            $result = $detector->detect($dir, [
                'supports_php_runtime' => true,
                'supports_node_runtime' => true,
                'default_runtime' => 'php:8.3',
                'default_entrypoint' => 'public/index.php',
                'default_package' => 'default',
            ]);

            $this->assertSame('symfony', $result['framework']);
            $this->assertSame('php', $result['language']);
            $this->assertSame('medium', $result['confidence']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_detects_django_with_manage_py(): void
    {
        $dir = storage_path('framework/testing/serverless-detector-'.uniqid());
        File::makeDirectory($dir, 0755, true);
        File::put($dir.'/manage.py', "# Django\n");
        File::put($dir.'/requirements.txt', "Django>=4.2\n");

        try {
            $detector = new ServerlessRuntimeDetector;
            $result = $detector->detect($dir, [
                'supports_php_runtime' => true,
                'supports_node_runtime' => true,
                'supports_python_runtime' => true,
                'default_python_runtime' => 'python3.12',
                'default_runtime' => 'nodejs:20',
                'default_entrypoint' => 'index',
                'default_package' => 'default',
            ]);

            $this->assertSame('django', $result['framework']);
            $this->assertSame('python', $result['language']);
            $this->assertSame('high', $result['confidence']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_detects_fastapi_from_requirements(): void
    {
        $dir = storage_path('framework/testing/serverless-detector-'.uniqid());
        File::makeDirectory($dir, 0755, true);
        File::put($dir.'/requirements.txt', "fastapi>=0.100\nuvicorn[standard]\n");

        try {
            $detector = new ServerlessRuntimeDetector;
            $result = $detector->detect($dir, [
                'supports_php_runtime' => true,
                'supports_node_runtime' => true,
                'supports_python_runtime' => true,
                'default_python_runtime' => 'python3.12',
                'default_runtime' => 'nodejs:20',
                'default_entrypoint' => 'index',
                'default_package' => 'default',
            ]);

            $this->assertSame('fastapi', $result['framework']);
            $this->assertSame('python', $result['language']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_detects_laravel_octane_flag_from_composer(): void
    {
        $dir = storage_path('framework/testing/serverless-detector-laravel-octane-'.uniqid());
        File::makeDirectory($dir, 0755, true);
        File::makeDirectory($dir.'/bootstrap', 0755, true);
        File::makeDirectory($dir.'/routes', 0755, true);
        File::makeDirectory($dir.'/public', 0755, true);
        File::put($dir.'/artisan', "#!/usr/bin/env php\n");
        File::put($dir.'/bootstrap/app.php', "<?php\n");
        File::put($dir.'/routes/web.php', "<?php\n");
        File::put($dir.'/public/index.php', "<?php\n");
        File::put($dir.'/composer.json', json_encode([
            'require' => [
                'laravel/framework' => '^12.0',
                'laravel/octane' => '^2.0',
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $detector = new ServerlessRuntimeDetector;
            $result = $detector->detect($dir, [
                'supports_php_runtime' => true,
                'supports_node_runtime' => true,
                'default_runtime' => 'php:8.3',
                'default_entrypoint' => 'public/index.php',
                'default_package' => 'default',
            ]);

            $this->assertSame('laravel', $result['framework']);
            $this->assertTrue($result['laravel_octane']);
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
