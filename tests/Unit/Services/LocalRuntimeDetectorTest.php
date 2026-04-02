<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\SiteType;
use App\Services\Deploy\LocalRuntimeDetector;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LocalRuntimeDetectorTest extends TestCase
{
    public function test_it_defaults_laravel_repos_to_docker_php_with_public_docroot(): void
    {
        $path = storage_path('framework/testing/local-runtime-laravel-'.uniqid());
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
        File::put($path.'/.env.example', "APP_NAME=Laravel\nAPP_ENV=local\n");

        $result = app(LocalRuntimeDetector::class)->detect($path, 'laravel-app');

        $this->assertSame('docker_web', $result['target_runtime']);
        $this->assertSame('docker', $result['target_kind']);
        $this->assertSame(SiteType::Php, $result['site_type']);
        $this->assertSame('/var/www/laravel-app/public', $result['document_root']);
        $this->assertSame('.env.example', $result['env_template']['path']);
        $this->assertContains('APP_NAME', $result['env_template']['keys']);
        $this->assertFalse($result['laravel_octane']);

        File::deleteDirectory($path);
    }

    public function test_it_sets_laravel_octane_when_composer_requires_octane_package(): void
    {
        $path = storage_path('framework/testing/local-runtime-laravel-octane-'.uniqid());
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
                'laravel/octane' => '^2.0',
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $result = app(LocalRuntimeDetector::class)->detect($path, 'laravel-octane-app');

        $this->assertTrue($result['laravel_octane']);

        File::deleteDirectory($path);
    }

    public function test_it_prefers_kubernetes_when_manifest_markers_exist(): void
    {
        $path = storage_path('framework/testing/local-runtime-kubernetes-'.uniqid());
        File::ensureDirectoryExists($path.'/k8s');
        File::put($path.'/package.json', json_encode([
            'scripts' => [
                'start' => 'node server.js',
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        File::put($path.'/k8s/deployment.yaml', <<<'YAML'
apiVersion: apps/v1
kind: Deployment
metadata:
  name: demo
  namespace: orbit-local
spec: {}
YAML);

        $result = app(LocalRuntimeDetector::class)->detect($path, 'demo');

        $this->assertSame('kubernetes_web', $result['target_runtime']);
        $this->assertSame('kubernetes', $result['target_kind']);
        $this->assertSame('orbit-local', $result['kubernetes_namespace']);
        $this->assertContains('k8s/deployment.yaml', $result['detected_files']);

        File::deleteDirectory($path);
    }

    public function test_it_detects_docker_signals_and_node_port_defaults(): void
    {
        $path = storage_path('framework/testing/local-runtime-docker-'.uniqid());
        File::ensureDirectoryExists($path);
        File::put($path.'/Dockerfile', "FROM node:20-alpine\n");
        File::put($path.'/package.json', json_encode([
            'scripts' => [
                'start' => 'next start --port 3010',
            ],
            'dependencies' => [
                'next' => '^15.0.0',
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $result = app(LocalRuntimeDetector::class)->detect($path, 'demo');

        $this->assertSame('docker_web', $result['target_runtime']);
        $this->assertSame('docker', $result['target_kind']);
        $this->assertSame(SiteType::Node, $result['site_type']);
        $this->assertSame(3010, $result['app_port']);
        $this->assertContains('Dockerfile', $result['detected_files']);

        File::deleteDirectory($path);
    }

    public function test_it_detects_python_django_and_default_port_without_package_json(): void
    {
        $path = storage_path('framework/testing/local-runtime-django-'.uniqid());
        File::ensureDirectoryExists($path);
        File::put($path.'/manage.py', "#!/usr/bin/env python\n");
        File::put($path.'/requirements.txt', "django>=4.2\n");

        $result = app(LocalRuntimeDetector::class)->detect($path, 'django-app');

        $this->assertSame('django', $result['framework']);
        $this->assertSame('python', $result['language']);
        $this->assertSame(SiteType::Node, $result['site_type']);
        $this->assertSame(8000, $result['app_port']);

        File::deleteDirectory($path);
    }
}
