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

        $yaml = (new DockerComposeArtifactBuilder)->build($site);

        $this->assertStringContainsString('docker-site', $yaml);
        $this->assertStringContainsString('80:4000', $yaml);
        $this->assertStringContainsString('image: dply/docker-site:latest', $yaml);
    }
}
