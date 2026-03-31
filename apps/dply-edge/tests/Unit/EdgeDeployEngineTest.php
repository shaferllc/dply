<?php

namespace Tests\Unit;

use App\Services\Deploy\EdgeDeployContext;
use App\Services\Deploy\EdgeDeployEngine;
use Tests\TestCase;

class EdgeDeployEngineTest extends TestCase
{
    public function test_stub_returns_json_and_revision(): void
    {
        $engine = new EdgeDeployEngine;
        $out = $engine->run(new EdgeDeployContext('my-app', 'astro', 'abc123'));

        $this->assertSame('edge-stub-revision-1', $out['sha']);
        $data = json_decode($out['output'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('edge', $data['provider']);
        $this->assertSame('stub', $data['status']);
        $this->assertSame('my-app', $data['application']);
        $this->assertSame('astro', $data['framework']);
        $this->assertSame('abc123', $data['git_ref']);
        $this->assertSame('api', $data['trigger']);
    }
}
