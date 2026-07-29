<?php

namespace Tests\Unit\Services\DockerRuntimeDockerfileBuilderTest;

use App\Enums\SiteType;
use App\Models\Site;
use App\Modules\Deploy\Services\DockerRuntimeDockerfileBuilder;

test('it points php sites at the detected public docroot', function () {
    $site = new Site([
        'type' => SiteType::Php,
        'document_root' => '/var/www/laravel-app/public',
        'php_version' => '8.3',
    ]);

    $dockerfile = (new DockerRuntimeDockerfileBuilder)->build($site);

    $this->assertStringContainsString('/var/www/html/public', $dockerfile);
    $this->assertStringContainsString('php:8.3-apache', $dockerfile);
});

test('it installs composer runtime dependencies for php sites', function () {
    $site = new Site([
        'type' => SiteType::Php,
        'php_version' => '8.3',
    ]);

    $dockerfile = (new DockerRuntimeDockerfileBuilder)->build($site);

    $this->assertStringContainsString('apt-get update', $dockerfile);
    $this->assertStringContainsString('apt-get install -y git unzip zip', $dockerfile);
    $this->assertStringContainsString('composer install --no-interaction --prefer-dist', $dockerfile);
});

test('it bootstraps laravel runtime directories for php sites', function () {
    $site = new Site([
        'type' => SiteType::Php,
        'php_version' => '8.3',
        'meta' => [
            'docker_runtime' => [
                'detected' => [
                    'framework' => 'laravel',
                ],
            ],
        ],
    ]);

    $dockerfile = (new DockerRuntimeDockerfileBuilder)->build($site);

    $this->assertStringContainsString('mkdir -p /var/www/html/database', $dockerfile);
    $this->assertStringContainsString('/var/www/html/storage/framework/sessions', $dockerfile);
});

test('it builds vite static sites before copying to nginx', function () {
    $site = new Site([
        'type' => SiteType::Static,
        'meta' => [
            'docker_runtime' => [
                'detected' => [
                    'framework' => 'vite_static',
                ],
            ],
        ],
    ]);

    $dockerfile = (new DockerRuntimeDockerfileBuilder)->build($site);

    $this->assertStringContainsString('FROM node:20-alpine AS build', $dockerfile);
    $this->assertStringContainsString('npm run build', $dockerfile);
    $this->assertStringContainsString('COPY --from=build /app/dist /usr/share/nginx/html', $dockerfile);
});

test('it keeps relative copy steps for subdirectory workspaces', function () {
    $site = new Site([
        'type' => SiteType::Node,
        'meta' => [
            'docker_runtime' => [
                'repository_subdirectory' => 'apps/web',
            ],
        ],
    ]);

    $dockerfile = (new DockerRuntimeDockerfileBuilder)->build($site);

    $this->assertStringContainsString('COPY package*.json ./', $dockerfile);
    $this->assertStringContainsString('COPY . .', $dockerfile);
});
