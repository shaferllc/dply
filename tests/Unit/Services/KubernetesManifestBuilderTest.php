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
            'env_file_content' => "APP_KEY=base64:test-key\nAPP_NAME=Cluster Site",
            'meta' => [
                'kubernetes_runtime' => [
                    'detected' => [
                        'framework' => 'laravel',
                    ],
                ],
            ],
        ]);

        $yaml = app(KubernetesManifestBuilder::class)->build($site, 'apps');

        $this->assertStringContainsString('namespace: apps', $yaml);
        $this->assertStringContainsString('name: cluster-site', $yaml);
        $this->assertStringContainsString('image: dply/cluster-site:latest', $yaml);
        $this->assertStringContainsString('targetPort: 80', $yaml);
        $this->assertStringContainsString('kind: ConfigMap', $yaml);
        $this->assertStringContainsString('kind: Secret', $yaml);
        $this->assertStringContainsString('name: cluster-site-config', $yaml);
        $this->assertStringContainsString('name: cluster-site-secret', $yaml);
    }
}
