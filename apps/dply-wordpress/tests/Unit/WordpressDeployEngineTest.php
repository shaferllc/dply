<?php

namespace Tests\Unit;

use App\Services\Deploy\WordpressDeployContext;
use App\Services\Deploy\WordpressDeployEngine;
use App\Services\Wordpress\Provisioners\LocalHostedWordpressProvisioner;
use Tests\TestCase;

class WordpressDeployEngineTest extends TestCase
{
    public function test_engine_returns_deployed_payload_and_revision_hash(): void
    {
        $engine = new WordpressDeployEngine(new LocalHostedWordpressProvisioner);
        $out = $engine->run(new WordpressDeployContext(
            applicationName: 'my-app',
            phpVersion: '8.3',
            gitRef: 'abc123',
            providerConfig: [
                'project' => [
                    'slug' => 'proj-slug',
                    'settings' => [
                        'runtime' => 'hosted',
                        'environment_id' => 'env-1',
                    ],
                ],
            ],
        ));

        $expectedSha = hash('sha256', 'proj-slug|abc123|8.3|my-app');
        $this->assertSame($expectedSha, $out['sha']);
        $data = json_decode($out['output'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('wordpress', $data['provider']);
        $this->assertSame('deployed', $data['status']);
        $this->assertSame('hosted', $data['runtime']);
        $this->assertSame('my-app', $data['application']);
        $this->assertSame('8.3', $data['php_version']);
        $this->assertSame('abc123', $data['git_ref']);
        $this->assertSame('api', $data['trigger']);
    }
}
