<?php

namespace Tests\Unit;

use App\Services\Deploy\CloudDeployContext;
use App\Services\Deploy\CloudDeployEngine;
use Tests\TestCase;

class CloudDeployEngineTest extends TestCase
{
    public function test_stub_returns_json_and_revision(): void
    {
        $engine = new CloudDeployEngine;
        $out = $engine->run(new CloudDeployContext('my-app', 'rails', 'abc123'));

        $this->assertSame('cloud-stub-revision-1', $out['sha']);
        $data = json_decode($out['output'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('cloud', $data['provider']);
        $this->assertSame('stub', $data['status']);
        $this->assertSame('my-app', $data['application']);
        $this->assertSame('rails', $data['stack']);
        $this->assertSame('abc123', $data['git_ref']);
        $this->assertSame('api', $data['trigger']);
    }
}
