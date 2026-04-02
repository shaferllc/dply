<?php

namespace Tests\Unit\Services;

use App\Enums\SiteType;
use App\Models\Site;
use App\Services\Deploy\KubernetesManifestBuilder;
use Tests\TestCase;

class KubernetesManifestBuilderTest extends TestCase
{
    public function test_it_builds_manifest_yaml_for_php_sites(): void
    {
        $site = new Site([
            'name' => 'Cluster Site',
            'slug' => 'cluster-site',
            'type' => SiteType::Php,
        ]);

        $yaml = (new KubernetesManifestBuilder)->build($site, 'apps');

        $this->assertStringContainsString('namespace: apps', $yaml);
        $this->assertStringContainsString('name: cluster-site', $yaml);
        $this->assertStringContainsString('image: dply/cluster-site:latest', $yaml);
        $this->assertStringContainsString('targetPort: 80', $yaml);
    }
}
