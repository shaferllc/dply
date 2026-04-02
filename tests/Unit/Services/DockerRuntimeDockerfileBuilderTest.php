<?php

namespace Tests\Unit\Services;

use App\Enums\SiteType;
use App\Models\Site;
use App\Services\Deploy\DockerRuntimeDockerfileBuilder;
use Tests\TestCase;

class DockerRuntimeDockerfileBuilderTest extends TestCase
{
    public function test_it_points_php_sites_at_the_detected_public_docroot(): void
    {
        $site = new Site([
            'type' => SiteType::Php,
            'document_root' => '/var/www/laravel-app/public',
            'php_version' => '8.3',
        ]);

        $dockerfile = (new DockerRuntimeDockerfileBuilder)->build($site);

        $this->assertStringContainsString('/var/www/html/public', $dockerfile);
        $this->assertStringContainsString('php:8.3-apache', $dockerfile);
    }

    public function test_it_installs_composer_runtime_dependencies_for_php_sites(): void
    {
        $site = new Site([
            'type' => SiteType::Php,
            'php_version' => '8.3',
        ]);

        $dockerfile = (new DockerRuntimeDockerfileBuilder)->build($site);

        $this->assertStringContainsString('apt-get update', $dockerfile);
        $this->assertStringContainsString('apt-get install -y git unzip zip', $dockerfile);
        $this->assertStringContainsString('composer install --no-interaction --prefer-dist', $dockerfile);
    }

    public function test_it_bootstraps_laravel_sqlite_storage_for_php_sites(): void
    {
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
        $this->assertStringContainsString('touch /var/www/html/database/database.sqlite', $dockerfile);
    }

    public function test_it_builds_vite_static_sites_before_copying_to_nginx(): void
    {
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
    }

    public function test_it_keeps_relative_copy_steps_for_subdirectory_workspaces(): void
    {
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
    }
}
