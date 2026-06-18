<?php

declare(strict_types=1);

namespace Tests\Unit\Services\LocalRuntimeDetectorTest;

use App\Enums\SiteType;
use App\Modules\Deploy\Services\LocalRuntimeDetector;
use Illuminate\Support\Facades\File;

test('it defaults laravel repos to docker php with public docroot', function () {
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

    expect($result['target_runtime'])->toBe('docker_web');
    expect($result['target_kind'])->toBe('docker');
    expect($result['site_type'])->toBe(SiteType::Php);
    expect($result['document_root'])->toBe('/var/www/laravel-app/public');
    expect($result['env_template']['path'])->toBe('.env.example');
    expect($result['env_template']['keys'])->toContain('APP_NAME');
    expect($result['laravel_octane'])->toBeFalse();

    File::deleteDirectory($path);
});
test('it sets laravel octane when composer requires octane package', function () {
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

    expect($result['laravel_octane'])->toBeTrue();

    File::deleteDirectory($path);
});
test('it prefers kubernetes when manifest markers exist', function () {
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

    expect($result['target_runtime'])->toBe('kubernetes_web');
    expect($result['target_kind'])->toBe('kubernetes');
    expect($result['kubernetes_namespace'])->toBe('orbit-local');
    expect($result['detected_files'])->toContain('k8s/deployment.yaml');

    File::deleteDirectory($path);
});
test('it detects docker signals and node port defaults', function () {
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

    expect($result['target_runtime'])->toBe('docker_web');
    expect($result['target_kind'])->toBe('docker');
    expect($result['site_type'])->toBe(SiteType::Node);
    expect($result['app_port'])->toBe(3010);
    expect($result['detected_files'])->toContain('Dockerfile');

    File::deleteDirectory($path);
});
test('it detects python django and default port without package json', function () {
    $path = storage_path('framework/testing/local-runtime-django-'.uniqid());
    File::ensureDirectoryExists($path);
    File::put($path.'/manage.py', "#!/usr/bin/env python\n");
    File::put($path.'/requirements.txt', "django>=4.2\n");

    $result = app(LocalRuntimeDetector::class)->detect($path, 'django-app');

    expect($result['framework'])->toBe('django');
    expect($result['language'])->toBe('python');
    expect($result['site_type'])->toBe(SiteType::Node);
    expect($result['app_port'])->toBe(8000);

    File::deleteDirectory($path);
});
