<?php


namespace Tests\Unit\Services\DockerComposeArtifactBuilderTest;
use App\Enums\SiteType;
use App\Models\Site;
use App\Services\Deploy\DockerComposeArtifactBuilder;

test('it builds compose yaml for node sites', function () {
    $site = new Site([
        'name' => 'Docker Site',
        'slug' => 'docker-site',
        'type' => SiteType::Node,
        'app_port' => 4000,
    ]);

    $yaml = app(DockerComposeArtifactBuilder::class)->build($site);

    $this->assertStringContainsString('docker-site', $yaml);
    $this->assertStringContainsString('80:4000', $yaml);
});

test('it uses port 80 for php sites', function () {
    $site = new Site([
        'name' => 'PHP Site',
        'slug' => 'php-site',
        'type' => SiteType::Php,
    ]);

    $yaml = app(DockerComposeArtifactBuilder::class)->build($site);

    $this->assertStringContainsString('80:80', $yaml);
});

test('it includes laravel app key from site env', function () {
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
});

test('it applies safe laravel runtime defaults when missing', function () {
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
});

test('it keeps a relative build context for subdirectory workspaces', function () {
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
});
