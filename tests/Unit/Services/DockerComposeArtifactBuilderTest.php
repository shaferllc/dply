<?php

namespace Tests\Unit\Services;

use App\Enums\SiteType;
use App\Models\Site;
use App\Services\Deploy\DockerComposeArtifactBuilder;
use Tests\TestCase;

class DockerComposeArtifactBuilderTest extends TestCase
{
    public function test_it_builds_compose_yaml_for_node_sites(): void
    {
        $site = new Site([
            'name' => 'Docker Site',
            'slug' => 'docker-site',
            'type' => SiteType::Node,
            'app_port' => 4000,
        ]);

        $yaml = app(DockerComposeArtifactBuilder::class)->build($site);

        $this->assertStringContainsString('docker-site', $yaml);
        $this->assertStringContainsString('80:4000', $yaml);
    }

    public function test_it_uses_port_80_for_php_sites(): void
    {
        $site = new Site([
            'name' => 'PHP Site',
            'slug' => 'php-site',
            'type' => SiteType::Php,
        ]);

        $yaml = app(DockerComposeArtifactBuilder::class)->build($site);

        $this->assertStringContainsString('80:80', $yaml);
    }

    public function test_it_includes_laravel_app_key_from_site_env(): void
    {
        $site = new Site([
            'name' => 'Laravel Site',
            'slug' => 'laravel-site',
            'type' => SiteType::Php,
            'env_file_content' => "APP_KEY=base64:test-key\nAPP_URL=http://laravel-site.local.dply.test",
            'meta' => [
                'docker_runtime' => [
                    'detected' => [
                        'framework' => 'laravel',
                    ],
                ],
            ],
        ]);

        $yaml = app(DockerComposeArtifactBuilder::class)->build($site);

        $this->assertStringContainsString('APP_KEY: "base64:test-key"', $yaml);
        $this->assertStringContainsString('APP_URL: "http://laravel-site.local.dply.test"', $yaml);
    }

    public function test_it_applies_safe_laravel_runtime_defaults_when_missing(): void
    {
        $site = new Site([
            'name' => 'Laravel Site',
            'slug' => 'laravel-site',
            'type' => SiteType::Php,
            'env_file_content' => "APP_KEY=base64:test-key\nAPP_URL=http://laravel-site.local.dply.test",
            'meta' => [
                'docker_runtime' => [
                    'detected' => [
                        'framework' => 'laravel',
                    ],
                ],
            ],
        ]);

        $yaml = app(DockerComposeArtifactBuilder::class)->build($site);

        $this->assertStringContainsString('SESSION_DRIVER: "file"', $yaml);
        $this->assertStringContainsString('CACHE_STORE: "file"', $yaml);
        $this->assertStringContainsString('QUEUE_CONNECTION: "sync"', $yaml);
    }

    public function test_it_keeps_a_relative_build_context_for_subdirectory_workspaces(): void
    {
        $site = new Site([
            'name' => 'Monorepo App',
            'slug' => 'monorepo-app',
            'type' => SiteType::Node,
            'app_port' => 3000,
            'meta' => [
                'docker_runtime' => [
                    'repository_subdirectory' => 'apps/web',
                ],
            ],
        ]);

        $yaml = app(DockerComposeArtifactBuilder::class)->build($site);

        $this->assertStringContainsString("build:\n      context: .", $yaml);
        $this->assertStringContainsString('dockerfile: Dockerfile.dply', $yaml);
    }
}
