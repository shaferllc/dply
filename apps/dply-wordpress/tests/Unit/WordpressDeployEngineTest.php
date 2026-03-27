<?php

namespace Tests\Unit;

use App\Services\Deploy\WordpressDeployContext;
use App\Services\Deploy\WordpressDeployEngine;
use Tests\TestCase;

class WordpressDeployEngineTest extends TestCase
{
    public function test_stub_returns_json_and_revision(): void
    {
        $engine = new WordpressDeployEngine;
        $out = $engine->run(new WordpressDeployContext('my-app', '8.3', 'abc123'));

        $this->assertSame('wp-stub-revision-1', $out['sha']);
        $data = json_decode($out['output'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('wordpress', $data['provider']);
        $this->assertSame('stub', $data['status']);
        $this->assertSame('my-app', $data['application']);
        $this->assertSame('8.3', $data['php_version']);
        $this->assertSame('abc123', $data['git_ref']);
        $this->assertSame('api', $data['trigger']);
    }
}
